<?php
/**
 * Plugin Settings Page
 *
 * Provides a modern, tabbed administrative settings interface for:
 *   - Tracking Providers (17TRACK, Track123, Packlink PRO, FedEx)
 *   - Auto-Sync Engine (Cron interval, inactivity threshold, automation rules)
 *   - Shipping Rate Comparator (Packlink PRO vs Paccofacile.it, fallback parcel dimensions)
 *   - Security & Anti-Scraping (Cloudflare Turnstile, IP rate limiting)
 *   - General & System (Uninstall data retention, health diagnostics, shortcode reference)
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WCOTL_Settings {

    public static function init(): void {
        add_action( 'admin_menu',    [ __CLASS__, 'register_menu' ] );
        add_action( 'admin_init',    [ __CLASS__, 'register_settings' ] );
        add_action( 'admin_post_wcotl_save_settings', [ __CLASS__, 'save_settings' ] );

        // AJAX: carrier auto-detect
        add_action( 'wp_ajax_wcotl_detect_carriers',  [ __CLASS__, 'ajax_detect_carriers' ] );
        // AJAX: fetch Track123 courier list
        add_action( 'wp_ajax_wcotl_get_track123_carriers', [ __CLASS__, 'ajax_get_track123_carriers' ] );
        // AJAX: fetch provider quota
        add_action( 'wp_ajax_wcotl_get_provider_quota', [ __CLASS__, 'ajax_get_provider_quota' ] );
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

    public static function register_menu(): void {
        add_submenu_page(
            'wcotl-tracking',
            __( 'Tracking Settings', 'wc-order-timeline' ),
            __( '⚙ Settings', 'wc-order-timeline' ),
            'manage_woocommerce',
            'wcotl-settings',
            [ __CLASS__, 'page' ]
        );
    }

    /* ------------------------------------------------------------------
     * Settings registration (used for sanitization wiring)
     * ------------------------------------------------------------------ */

    public static function register_settings(): void {
        register_setting( 'wcotl_settings_group', 'wcotl_17track_api_key',           'sanitize_text_field' );
        register_setting( 'wcotl_settings_group', 'wcotl_track123_api_key',          'sanitize_text_field' );
        register_setting( 'wcotl_settings_group', 'wcotl_packlink_api_key',          'sanitize_text_field' );
        register_setting( 'wcotl_settings_group', 'wcotl_fedex_client_id',           'sanitize_text_field' );
        register_setting( 'wcotl_settings_group', 'wcotl_fedex_client_secret',       'sanitize_text_field' );
        register_setting( 'wcotl_settings_group', 'wcotl_fedex_environment',         'sanitize_key' );
        register_setting( 'wcotl_settings_group', 'wcotl_sync_interval',             'absint' );
        register_setting( 'wcotl_settings_group', 'wcotl_inactivity_days',           'absint' );
        register_setting( 'wcotl_settings_group', 'wcotl_delete_data_on_uninstall',  'absint' );
        register_setting( 'wcotl_settings_group', 'wcotl_turnstile_site_key',        'sanitize_text_field' );
        register_setting( 'wcotl_settings_group', 'wcotl_turnstile_secret_key',      'sanitize_text_field' );
        register_setting( 'wcotl_settings_group', 'wcotl_rate_limit_max_requests',   'absint' );
        register_setting( 'wcotl_settings_group', 'wcotl_paccofacile_account_number', 'sanitize_text_field' );
        register_setting( 'wcotl_settings_group', 'wcotl_paccofacile_api_key',        'sanitize_text_field' );
        register_setting( 'wcotl_settings_group', 'wcotl_paccofacile_token',          'sanitize_text_field' );
        register_setting( 'wcotl_settings_group', 'wcotl_paccofacile_environment',    'sanitize_key' );
        register_setting( 'wcotl_settings_group', 'wcotl_default_package_weight',     'floatval' );
        register_setting( 'wcotl_settings_group', 'wcotl_default_package_length',     'absint' );
        register_setting( 'wcotl_settings_group', 'wcotl_default_package_width',      'absint' );
        register_setting( 'wcotl_settings_group', 'wcotl_default_package_height',     'absint' );
    }

    /* ------------------------------------------------------------------
     * Save handler
     * ------------------------------------------------------------------ */

    public static function save_settings(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Not allowed.', 'wc-order-timeline' ) );
        }
        check_admin_referer( 'wcotl_settings_nonce' );

        $current_tab  = sanitize_key( $_POST['current_tab'] ?? 'providers' );
        $allowed_tabs = [ 'providers', 'sync', 'rates', 'security', 'system' ];
        if ( ! in_array( $current_tab, $allowed_tabs, true ) ) {
            $current_tab = 'providers';
        }

        // --- Tracking Providers Tab ---
        if ( isset( $_POST['wcotl_17track_api_key'] ) ) {
            update_option( 'wcotl_17track_api_key', sanitize_text_field( wp_unslash( $_POST['wcotl_17track_api_key'] ) ) );
        }
        if ( isset( $_POST['wcotl_track123_api_key'] ) ) {
            $track123_key = sanitize_text_field( wp_unslash( $_POST['wcotl_track123_api_key'] ) );
            update_option( 'wcotl_track123_api_key', $track123_key );
            delete_transient( 'wcotl_track123_quota_' . md5( $track123_key ) );
        }
        if ( isset( $_POST['wcotl_packlink_api_key'] ) ) {
            update_option( 'wcotl_packlink_api_key', sanitize_text_field( wp_unslash( $_POST['wcotl_packlink_api_key'] ) ) );
        }
        if ( isset( $_POST['wcotl_fedex_client_id'] ) ) {
            update_option( 'wcotl_fedex_client_id', sanitize_text_field( wp_unslash( $_POST['wcotl_fedex_client_id'] ) ) );
        }
        if ( isset( $_POST['wcotl_fedex_client_secret'] ) ) {
            update_option( 'wcotl_fedex_client_secret', sanitize_text_field( wp_unslash( $_POST['wcotl_fedex_client_secret'] ) ) );
        }
        if ( isset( $_POST['wcotl_fedex_environment'] ) ) {
            $fedex_environment = sanitize_key( wp_unslash( $_POST['wcotl_fedex_environment'] ) );
            update_option( 'wcotl_fedex_environment', $fedex_environment === 'sandbox' ? 'sandbox' : 'production' );
        }

        // --- Auto-Sync Engine Tab ---
        if ( isset( $_POST['wcotl_sync_interval'] ) ) {
            $interval = max( 1, absint( $_POST['wcotl_sync_interval'] ) );
            update_option( 'wcotl_sync_interval', $interval );
        }
        if ( isset( $_POST['wcotl_inactivity_days'] ) ) {
            $inactive = max( 1, absint( $_POST['wcotl_inactivity_days'] ) );
            update_option( 'wcotl_inactivity_days', $inactive );
        }

        // --- Rate Comparator Tab ---
        if ( isset( $_POST['wcotl_paccofacile_account_number'] ) ) {
            update_option( 'wcotl_paccofacile_account_number', sanitize_text_field( wp_unslash( $_POST['wcotl_paccofacile_account_number'] ) ) );
        }
        if ( isset( $_POST['wcotl_paccofacile_api_key'] ) ) {
            update_option( 'wcotl_paccofacile_api_key', sanitize_text_field( wp_unslash( $_POST['wcotl_paccofacile_api_key'] ) ) );
        }
        if ( isset( $_POST['wcotl_paccofacile_token'] ) ) {
            update_option( 'wcotl_paccofacile_token', sanitize_text_field( wp_unslash( $_POST['wcotl_paccofacile_token'] ) ) );
        }
        if ( isset( $_POST['wcotl_paccofacile_environment'] ) ) {
            $pacco_env = sanitize_key( wp_unslash( $_POST['wcotl_paccofacile_environment'] ) );
            update_option( 'wcotl_paccofacile_environment', $pacco_env === 'sandbox' ? 'sandbox' : 'live' );
        }
        if ( isset( $_POST['wcotl_default_package_weight'] ) ) {
            $def_w = max( 0.01, floatval( wp_unslash( $_POST['wcotl_default_package_weight'] ) ) );
            update_option( 'wcotl_default_package_weight', $def_w );
        }
        if ( isset( $_POST['wcotl_default_package_length'] ) ) {
            $def_l = max( 1, absint( $_POST['wcotl_default_package_length'] ) );
            update_option( 'wcotl_default_package_length', $def_l );
        }
        if ( isset( $_POST['wcotl_default_package_width'] ) ) {
            $def_w_dim = max( 1, absint( $_POST['wcotl_default_package_width'] ) );
            update_option( 'wcotl_default_package_width', $def_w_dim );
        }
        if ( isset( $_POST['wcotl_default_package_height'] ) ) {
            $def_h = max( 1, absint( $_POST['wcotl_default_package_height'] ) );
            update_option( 'wcotl_default_package_height', $def_h );
        }

        // --- Security & Anti-Scraping Tab ---
        if ( isset( $_POST['wcotl_turnstile_site_key'] ) ) {
            update_option( 'wcotl_turnstile_site_key', sanitize_text_field( wp_unslash( $_POST['wcotl_turnstile_site_key'] ) ) );
        }
        if ( isset( $_POST['wcotl_turnstile_secret_key'] ) ) {
            update_option( 'wcotl_turnstile_secret_key', sanitize_text_field( wp_unslash( $_POST['wcotl_turnstile_secret_key'] ) ) );
        }
        if ( isset( $_POST['wcotl_rate_limit_max_requests'] ) ) {
            $rate_limit_max = max( 1, absint( $_POST['wcotl_rate_limit_max_requests'] ) );
            update_option( 'wcotl_rate_limit_max_requests', $rate_limit_max );
        }

        // --- General & System Tab ---
        if ( isset( $_POST['wcotl_save_system_settings'] ) || isset( $_POST['wcotl_save_general_settings'] ) || $current_tab === 'system' ) {
            $delete_data = ! empty( $_POST['wcotl_delete_data_on_uninstall'] ) ? 1 : 0;
            update_option( 'wcotl_delete_data_on_uninstall', $delete_data );
        }

        $redirect_url = admin_url( 'admin.php?page=wcotl-settings&tab=' . $current_tab . '&saved=1' );
        wp_redirect( $redirect_url );
        exit;
    }

    /* ------------------------------------------------------------------
     * AJAX Endpoints
     * ------------------------------------------------------------------ */

    public static function ajax_detect_carriers(): void {
        check_ajax_referer( 'wcotl_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( __( 'Not allowed.', 'wc-order-timeline' ) );
        }

        $number        = sanitize_text_field( wp_unslash( $_POST['number'] ?? '' ) );
        $provider_slug = sanitize_key( wp_unslash( $_POST['provider'] ?? '17track' ) );
        $provider      = match( $provider_slug ) {
            'track123' => WCOTL_Auto_Sync::get_track123_provider(),
            'packlink' => WCOTL_Auto_Sync::get_packlink_provider(),
            'fedex'    => WCOTL_Auto_Sync::get_fedex_provider(),
            default    => WCOTL_Auto_Sync::get_provider(),
        };

        if ( ! $provider ) {
            wp_send_json_error( __( 'No API key configured for the selected provider.', 'wc-order-timeline' ) );
        }

        $carriers = $provider->detect_carriers( $number );
        wp_send_json_success( $carriers );
    }

    public static function ajax_get_track123_carriers(): void {
        check_ajax_referer( 'wcotl_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( __( 'Not allowed.', 'wc-order-timeline' ) );
        }

        $provider = WCOTL_Auto_Sync::get_track123_provider();
        if ( ! $provider ) {
            wp_send_json_error( __( 'Track123 API is not configured.', 'wc-order-timeline' ) );
        }

        $carriers = $provider->get_courier_list();
        wp_send_json_success( $carriers );
    }

    public static function ajax_get_provider_quota(): void {
        check_ajax_referer( 'wcotl_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( __( 'Not allowed.', 'wc-order-timeline' ) );
        }

        $provider_slug = sanitize_key( $_POST['provider'] ?? 'track123' );
        $force_refresh = ! empty( $_POST['force_refresh'] );

        $provider = WCOTL_Auto_Sync::get_provider_by_slug( $provider_slug );
        if ( ! $provider || ! $provider->is_configured() ) {
            wp_send_json_error( __( 'Provider is not configured.', 'wc-order-timeline' ) );
        }

        $quota = $provider->get_quota( $force_refresh );
        wp_send_json_success( $quota );
    }

    public static function ajax_save_auto_tracking(): void {
        check_ajax_referer( 'wcotl_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( __( 'Not allowed.', 'wc-order-timeline' ) );
        }

        $tracking_code = sanitize_text_field( wp_unslash( $_POST['tracking_code'] ?? '' ) );
        $real_number   = sanitize_text_field( wp_unslash( $_POST['real_number'] ?? '' ) );
        $provider_slug = sanitize_key( $_POST['provider'] ?? '17track' );

        if ( ! in_array( $provider_slug, [ '17track', 'track123', 'packlink', 'fedex' ], true ) ) {
            $provider_slug = '17track';
        }

        if ( $provider_slug === 'track123' ) {
            $carrier_code = sanitize_text_field( wp_unslash( $_POST['carrier_code'] ?? '' ) );
            if ( $carrier_code === '0' ) {
                $carrier_code = '';
            }
        } elseif ( $provider_slug === 'packlink' || $provider_slug === 'fedex' ) {
            $carrier_code = '';
        } else {
            $carrier_code = absint( $_POST['carrier_code'] ?? 0 );
        }

        if ( ! $tracking_code ) {
            wp_send_json_error( __( 'Missing tracking code.', 'wc-order-timeline' ) );
        }

        global $wpdb;
        $table      = $wpdb->prefix . 'order_timeline';
        $meta_table = $wpdb->prefix . 'order_timeline_meta';
        $exists_in_steps = $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM $table WHERE tracking_code = %s LIMIT 1", $tracking_code ) );
        $exists_in_meta  = $exists_in_steps ? null : $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM $meta_table WHERE tracking_code = %s LIMIT 1", $tracking_code ) );
        if ( ! $exists_in_steps && ! $exists_in_meta ) {
            wp_send_json_error( __( 'Unknown tracking code.', 'wc-order-timeline' ) );
        }

        if ( $real_number ) {
            $prev_real_number  = WCOTL_DB::get_meta( $tracking_code, WCOTL_Auto_Sync::META_REAL_NUMBER );
            $raw_prev_carrier  = WCOTL_DB::get_meta( $tracking_code, WCOTL_Auto_Sync::META_CARRIER_CODE ) ?: '';
            $prev_carrier_code = is_numeric( $raw_prev_carrier ) ? (int) $raw_prev_carrier : $raw_prev_carrier;
            $prev_provider     = WCOTL_DB::get_meta( $tracking_code, WCOTL_Auto_Sync::META_PROVIDER ) ?: '17track';

            $changed = ( $prev_real_number !== $real_number ) || ( (string) $prev_carrier_code !== (string) $carrier_code ) || ( $prev_provider !== $provider_slug );

            WCOTL_DB::set_meta( $tracking_code, WCOTL_Auto_Sync::META_REAL_NUMBER,  $real_number );
            WCOTL_DB::set_meta( $tracking_code, WCOTL_Auto_Sync::META_PROVIDER,     $provider_slug );
            if ( $provider_slug === 'packlink' || $provider_slug === 'fedex' ) {
                WCOTL_DB::set_meta( $tracking_code, WCOTL_Auto_Sync::META_CARRIER_CODE, '' );
            } elseif ( $provider_slug === 'track123' ) {
                WCOTL_DB::set_meta( $tracking_code, WCOTL_Auto_Sync::META_CARRIER_CODE, $carrier_code );
            } else {
                WCOTL_DB::set_meta( $tracking_code, WCOTL_Auto_Sync::META_CARRIER_CODE, $carrier_code ?: '' );
            }
            if ( $changed ) {
                WCOTL_DB::set_meta( $tracking_code, WCOTL_Auto_Sync::META_REGISTERED,   '' );
                WCOTL_DB::set_meta( $tracking_code, WCOTL_Auto_Sync::META_SYNC_STOPPED,  '' );
                WCOTL_DB::set_meta( $tracking_code, WCOTL_Auto_Sync::META_STOP_REASON,   '' );
                WCOTL_DB::set_meta( $tracking_code, WCOTL_Auto_Sync::META_LAST_STATUS,   '' );
            }
            WCOTL_DB::set_meta( $tracking_code, WCOTL_Auto_Sync::META_NEXT_SYNC_AT,  '' );
        } else {
            WCOTL_DB::set_meta( $tracking_code, WCOTL_Auto_Sync::META_REAL_NUMBER, '' );
            WCOTL_DB::set_meta( $tracking_code, WCOTL_Auto_Sync::META_PROVIDER,    '' );
        }

        wp_send_json_success( __( 'Saved.', 'wc-order-timeline' ) );
    }

    public static function ajax_sync_now(): void {
        check_ajax_referer( 'wcotl_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( __( 'Not allowed.', 'wc-order-timeline' ) );
        }

        $tracking_code = sanitize_text_field( wp_unslash( $_POST['tracking_code'] ?? '' ) );
        if ( ! $tracking_code ) {
            wp_send_json_error( __( 'Missing tracking code.', 'wc-order-timeline' ) );
        }

        global $wpdb;
        $table      = $wpdb->prefix . 'order_timeline';
        $meta_table = $wpdb->prefix . 'order_timeline_meta';
        $exists_in_steps = $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM $table WHERE tracking_code = %s LIMIT 1", $tracking_code ) );
        $exists_in_meta  = $exists_in_steps ? null : $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM $meta_table WHERE tracking_code = %s LIMIT 1", $tracking_code ) );
        if ( ! $exists_in_steps && ! $exists_in_meta ) {
            wp_send_json_error( __( 'Unknown tracking code.', 'wc-order-timeline' ) );
        }

        $result = WCOTL_Auto_Sync::sync_code( $tracking_code );

        if ( $result['step'] !== 'complete' ) {
            wp_send_json_error( [
                'step'  => $result['step'],
                'error' => $result['error'] ?? __( 'Unknown error.', 'wc-order-timeline' ),
            ] );
        }

        wp_send_json_success( $result );
    }

    public static function ajax_resume_sync(): void {
        check_ajax_referer( 'wcotl_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( __( 'Not allowed.', 'wc-order-timeline' ) );
        }

        $tracking_code = sanitize_text_field( wp_unslash( $_POST['tracking_code'] ?? '' ) );
        if ( ! $tracking_code ) {
            wp_send_json_error( __( 'Missing tracking code.', 'wc-order-timeline' ) );
        }

        global $wpdb;
        $table      = $wpdb->prefix . 'order_timeline';
        $meta_table = $wpdb->prefix . 'order_timeline_meta';
        $exists_in_steps = $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM $table WHERE tracking_code = %s LIMIT 1", $tracking_code ) );
        $exists_in_meta  = $exists_in_steps ? null : $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM $meta_table WHERE tracking_code = %s LIMIT 1", $tracking_code ) );
        if ( ! $exists_in_steps && ! $exists_in_meta ) {
            wp_send_json_error( __( 'Unknown tracking code.', 'wc-order-timeline' ) );
        }

        WCOTL_DB::set_meta( $tracking_code, WCOTL_Auto_Sync::META_SYNC_STOPPED, '' );
        WCOTL_DB::set_meta( $tracking_code, WCOTL_Auto_Sync::META_STOP_REASON,  '' );
        WCOTL_DB::set_meta( $tracking_code, WCOTL_Auto_Sync::META_LAST_STATUS,  '' );
        WCOTL_DB::set_meta( $tracking_code, WCOTL_Auto_Sync::META_NEXT_SYNC_AT, '' );

        wp_send_json_success( __( 'Sync resumed.', 'wc-order-timeline' ) );
    }

    public static function ajax_set_uninstall_data_preference(): void {
        check_ajax_referer( 'wcotl_uninstall_nonce', 'nonce' );
        if ( ! current_user_can( 'delete_plugins' ) && ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( __( 'Not allowed.', 'wc-order-timeline' ) );
        }

        $delete_data = ! empty( $_POST['delete_data'] ) ? 1 : 0;
        update_option( 'wcotl_delete_data_on_uninstall', $delete_data );

        wp_send_json_success( [ 'delete_data' => $delete_data ] );
    }

    /* ------------------------------------------------------------------
     * Settings Page UI
     * ------------------------------------------------------------------ */

    public static function page(): void {
        $allowed_tabs = [
            'providers' => __( 'Tracking Providers', 'wc-order-timeline' ),
            'sync'      => __( 'Auto-Sync Engine', 'wc-order-timeline' ),
            'rates'     => __( 'Rate Comparator', 'wc-order-timeline' ),
            'security'  => __( 'Security & Protection', 'wc-order-timeline' ),
            'system'    => __( 'General & System', 'wc-order-timeline' ),
        ];

        $current_tab = isset( $_GET['tab'] ) && array_key_exists( $_GET['tab'], $allowed_tabs )
            ? sanitize_key( $_GET['tab'] )
            : 'providers';

        $saved = isset( $_GET['saved'] );

        // Providers data
        $api_key              = get_option( 'wcotl_17track_api_key', '' );
        $track123_api_key     = get_option( 'wcotl_track123_api_key', '' );
        $packlink_api_key     = get_option( 'wcotl_packlink_api_key', '' );
        $fedex_client_id      = get_option( 'wcotl_fedex_client_id', '' );
        $fedex_client_secret  = get_option( 'wcotl_fedex_client_secret', '' );
        $fedex_environment    = get_option( 'wcotl_fedex_environment', 'production' );
        $fedex_configured     = ( ! empty( $fedex_client_id ) && ! empty( $fedex_client_secret ) );
        $packlink_active      = WCOTL_Packlink_Provider::is_packlink_plugin_active();

        // Auto-sync data
        $sync_interval        = max( 1, (int) get_option( 'wcotl_sync_interval', 1 ) );
        $inactivity_days      = max( 1, (int) get_option( 'wcotl_inactivity_days', 45 ) );
        $next_sync            = wp_next_scheduled( WCOTL_Auto_Sync::CRON_HOOK );
        $admin_email          = get_option( 'admin_email' );

        // Rate comparator data
        $paccofacile_acc        = get_option( 'wcotl_paccofacile_account_number', '' );
        $paccofacile_key        = get_option( 'wcotl_paccofacile_api_key', '' );
        $paccofacile_token      = get_option( 'wcotl_paccofacile_token', '' );
        $paccofacile_env        = get_option( 'wcotl_paccofacile_environment', 'live' );
        $paccofacile_prov       = new WCOTL_Paccofacile_Provider( $paccofacile_acc, $paccofacile_key, $paccofacile_token, $paccofacile_env );
        $paccofacile_configured = $paccofacile_prov->is_configured();
        $paccofacile_plugin     = WCOTL_Paccofacile_Provider::is_paccofacile_plugin_active();
        $default_package_weight = (float) get_option( 'wcotl_default_package_weight', 1.0 );
        $default_package_length = (int) get_option( 'wcotl_default_package_length', 20 );
        $default_package_width  = (int) get_option( 'wcotl_default_package_width', 20 );
        $default_package_height = (int) get_option( 'wcotl_default_package_height', 10 );

        // Security data
        $turnstile_site_key   = get_option( 'wcotl_turnstile_site_key', '' );
        $turnstile_secret_key = get_option( 'wcotl_turnstile_secret_key', '' );
        $rate_limit_max       = max( 1, (int) get_option( 'wcotl_rate_limit_max_requests', 15 ) );
        $turnstile_configured = ( ! empty( $turnstile_site_key ) && ! empty( $turnstile_secret_key ) );

        // System data
        $delete_data_on_uninstall = (int) get_option( 'wcotl_delete_data_on_uninstall', 0 );
        ?>

        <style>
            .wcotl-settings-wrap { max-width: 1040px; margin-top: 15px; }
            .wcotl-tab-content { margin-top: 20px; }
            .wcotl-settings-card {
                background: #fff;
                border: 1px solid #ccd0d4;
                border-radius: 6px;
                padding: 22px 26px;
                margin-bottom: 22px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.04);
            }
            .wcotl-settings-card h2 {
                margin: 0 0 8px 0;
                font-size: 16px;
                font-weight: 600;
                color: #1d2327;
                display: flex;
                align-items: center;
                gap: 8px;
            }
            .wcotl-settings-card .card-description {
                font-size: 13px;
                color: #50575e;
                margin-bottom: 18px;
                line-height: 1.5;
            }
            .wcotl-badge-pill {
                display: inline-flex;
                align-items: center;
                gap: 4px;
                font-size: 11px;
                font-weight: 600;
                padding: 3px 8px;
                border-radius: 12px;
                line-height: 1.2;
            }
            .wcotl-badge-success { background: #edfaef; color: #008a20; border: 1px solid #b8e6bf; }
            .wcotl-badge-warning { background: #fcf9e8; color: #9a6e1a; border: 1px solid #f0c060; }
            .wcotl-badge-error   { background: #fcf0f1; color: #d63638; border: 1px solid #f5c2c7; }
            .wcotl-badge-neutral { background: #f0f0f1; color: #646970; border: 1px solid #c3c4c7; }

            .wcotl-status-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
                gap: 12px;
                margin-bottom: 20px;
            }
            .wcotl-status-tile {
                background: #fff;
                border: 1px solid #ccd0d4;
                border-radius: 6px;
                padding: 12px 14px;
                display: flex;
                align-items: center;
                justify-content: space-between;
                box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            }
            .wcotl-status-tile .tile-title { font-size: 13px; font-weight: 600; color: #1d2327; }
            .wcotl-status-tile .tile-sub   { font-size: 11px; color: #646970; margin-top: 2px; }

            .wcotl-field-group { margin-bottom: 18px; }
            .wcotl-field-group:last-child { margin-bottom: 0; }
            .wcotl-field-group label.field-label {
                display: block;
                font-weight: 600;
                font-size: 13px;
                margin-bottom: 5px;
                color: #1d2327;
            }
            .wcotl-field-help {
                font-size: 12px;
                color: #646970;
                margin-top: 4px;
                line-height: 1.4;
            }
            .wcotl-input-with-button { display: flex; gap: 8px; max-width: 580px; }
            .wcotl-input-with-button input { flex: 1; font-family: monospace; }

            .wcotl-notice-callout {
                background: #f0f6fc;
                border-left: 4px solid #2271b1;
                padding: 10px 14px;
                border-radius: 3px;
                font-size: 12px;
                color: #1d2327;
                line-height: 1.5;
                margin: 14px 0;
            }
            .wcotl-notice-callout.warning {
                background: #fff8e5;
                border-left-color: #dba617;
                color: #614700;
            }
            .wcotl-notice-callout.info {
                background: #f0f7ff;
                border-left-color: #0073aa;
                color: #0c4a6e;
            }
            .wcotl-footer-actions {
                margin-top: 24px;
                padding-top: 16px;
                border-top: 1px solid #dcdcde;
                display: flex;
                align-items: center;
                gap: 12px;
            }
        </style>

        <div class="wrap wcotl-admin wcotl-settings-wrap">
            <h1>
                <span class="dashicons dashicons-admin-settings" style="font-size:28px;width:28px;height:28px;"></span>
                <?php esc_html_e( 'Tracking Settings', 'wc-order-timeline' ); ?>
            </h1>

            <?php if ( $saved ) : ?>
                <div class="notice notice-success is-dismissible" style="margin-top:14px;">
                    <p><strong><?php esc_html_e( 'Settings updated successfully.', 'wc-order-timeline' ); ?></strong></p>
                </div>
            <?php endif; ?>

            <!-- Navigation Tabs -->
            <nav class="nav-tab-wrapper" style="margin-top:18px;">
                <?php foreach ( $allowed_tabs as $tab_key => $tab_label ) :
                    $active_class = ( $current_tab === $tab_key ) ? 'nav-tab-active' : '';
                    $icon = match( $tab_key ) {
                        'providers' => 'dashicons-location-alt',
                        'sync'      => 'dashicons-update',
                        'rates'     => 'dashicons-money-alt',
                        'security'  => 'dashicons-shield',
                        'system'    => 'dashicons-admin-tools',
                        default     => 'dashicons-admin-generic',
                    };
                ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=wcotl-settings&tab=' . $tab_key ) ); ?>"
                       class="nav-tab <?php echo esc_attr( $active_class ); ?>">
                        <span class="dashicons <?php echo esc_attr( $icon ); ?>" style="font-size:16px;width:16px;height:16px;line-height:16px;vertical-align:text-top;margin-right:4px;"></span>
                        <?php echo esc_html( $tab_label ); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="wcotl-tab-content">

                <!-- ============================================================== -->
                <!-- TAB 1: TRACKING PROVIDERS                                      -->
                <!-- ============================================================== -->
                <?php if ( $current_tab === 'providers' ) : ?>

                    <!-- Provider Summary Tiles -->
                    <div class="wcotl-status-grid">
                        <div class="wcotl-status-tile">
                            <div>
                                <div class="tile-title">17TRACK</div>
                                <div class="tile-sub">Carrier Tracking API</div>
                            </div>
                            <?php if ( $api_key ) : ?>
                                <span class="wcotl-badge-pill wcotl-badge-success">✓ Active</span>
                            <?php else : ?>
                                <span class="wcotl-badge-pill wcotl-badge-neutral">Inactive</span>
                            <?php endif; ?>
                        </div>

                        <div class="wcotl-status-tile">
                            <div>
                                <div class="tile-title">Track123</div>
                                <div class="tile-sub">REST API v2.1 (1,700+ couriers)</div>
                            </div>
                            <?php if ( $track123_api_key ) : ?>
                                <span class="wcotl-badge-pill wcotl-badge-success">✓ Active</span>
                            <?php else : ?>
                                <span class="wcotl-badge-pill wcotl-badge-neutral">Inactive</span>
                            <?php endif; ?>
                        </div>

                        <div class="wcotl-status-tile">
                            <div>
                                <div class="tile-title">Packlink PRO</div>
                                <div class="tile-sub">Shipment Reference Sync</div>
                            </div>
                            <?php if ( ! $packlink_active ) : ?>
                                <span class="wcotl-badge-pill wcotl-badge-warning">Plugin Needed</span>
                            <?php elseif ( $packlink_api_key ) : ?>
                                <span class="wcotl-badge-pill wcotl-badge-success">✓ Active</span>
                            <?php else : ?>
                                <span class="wcotl-badge-pill wcotl-badge-neutral">Inactive</span>
                            <?php endif; ?>
                        </div>

                        <div class="wcotl-status-tile">
                            <div>
                                <div class="tile-title">FedEx</div>
                                <div class="tile-sub">REST API (<?php echo esc_html( ucfirst( $fedex_environment ) ); ?>)</div>
                            </div>
                            <?php if ( $fedex_configured ) : ?>
                                <span class="wcotl-badge-pill wcotl-badge-success">✓ Active</span>
                            <?php else : ?>
                                <span class="wcotl-badge-pill wcotl-badge-neutral">Inactive</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <?php wp_nonce_field( 'wcotl_settings_nonce' ); ?>
                        <input type="hidden" name="action" value="wcotl_save_settings">
                        <input type="hidden" name="current_tab" value="providers">

                        <!-- 17TRACK -->
                        <div class="wcotl-settings-card" id="provider-17track">
                            <h2>
                                <span class="dashicons dashicons-share-alt" style="color:#2271b1;"></span>
                                17TRACK API
                                <?php if ( $api_key ) : ?>
                                    <span class="wcotl-badge-pill wcotl-badge-success" style="margin-left:auto;">✓ Configured</span>
                                <?php endif; ?>
                            </h2>
                            <p class="card-description">
                                Connect to 17TRACK for automated tracking updates across global carriers.
                                Generate an API key in your account at <a href="https://api.17track.net" target="_blank">api.17track.net ↗</a>.
                            </p>

                            <div class="wcotl-field-group">
                                <label class="field-label" for="wcotl_17track_api_key">17TRACK API Key</label>
                                <div class="wcotl-input-with-button">
                                    <input type="password" id="wcotl_17track_api_key" name="wcotl_17track_api_key"
                                           value="<?php echo esc_attr( $api_key ); ?>"
                                           placeholder="Paste your 17TRACK API key">
                                    <button type="button" class="button" onclick="wcotlToggleField('wcotl_17track_api_key', this)">Show</button>
                                </div>
                                <div class="wcotl-field-help">Leave empty to disable 17TRACK automatic tracking.</div>
                            </div>

                            <div class="wcotl-notice-callout info">
                                ℹ <strong>17TRACK Quota Note:</strong> 17TRACK does not provide an API endpoint to programmatically query remaining account balance. Track and monitor your remaining quotas directly in the <a href="https://api.17track.net" target="_blank">17TRACK Management Console ↗</a>.
                            </div>
                        </div>

                        <!-- Track123 -->
                        <div class="wcotl-settings-card" id="provider-track123">
                            <h2>
                                <span class="dashicons dashicons-location" style="color:#008a20;"></span>
                                Track123 API
                                <?php if ( $track123_api_key ) : ?>
                                    <span class="wcotl-badge-pill wcotl-badge-success" style="margin-left:auto;">✓ Configured</span>
                                <?php endif; ?>
                            </h2>
                            <p class="card-description">
                                Connects to the Track123 REST API v2.1 to track shipments across 1,700+ couriers worldwide with multi-carrier checkpoint synchronization and auto-detection.
                                Get your API Secret from the <a href="https://member.track123.com/api" target="_blank">Track123 Admin Portal (Developers → API) ↗</a>.
                            </p>

                            <div class="wcotl-field-group">
                                <label class="field-label" for="wcotl_track123_api_key">Track123 API Key / Secret</label>
                                <div class="wcotl-input-with-button">
                                    <input type="password" id="wcotl_track123_api_key" name="wcotl_track123_api_key"
                                           value="<?php echo esc_attr( $track123_api_key ); ?>"
                                           placeholder="Paste your Track123 API Secret">
                                    <button type="button" class="button" onclick="wcotlToggleField('wcotl_track123_api_key', this)">Show</button>
                                </div>
                                <div class="wcotl-field-help">Leave empty to disable Track123 automatic tracking.</div>
                            </div>

                            <?php if ( $track123_api_key ) :
                                $t123_provider = WCOTL_Auto_Sync::get_track123_provider();
                                $t123_quota    = $t123_provider?->get_quota();
                            ?>
                                <div id="wcotl-track123-quota-box" style="margin:14px 0;padding:12px 14px;background:#f8f9fa;border:1px solid #dcdcde;border-radius:4px;max-width:580px;">
                                    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                                        <div>
                                            <strong style="font-size:12px;color:#1d2327;">Live Account Quota:</strong>
                                            <span id="wcotl-t123-quota-display" style="margin-left:6px;font-size:13px;">
                                                <?php if ( ! empty( $t123_quota['error'] ) ) : ?>
                                                    <span style="color:#d63638;"><?php echo esc_html( $t123_quota['error'] ); ?></span>
                                                <?php elseif ( isset( $t123_quota['remaining'], $t123_quota['total'] ) ) : ?>
                                                    <span style="font-weight:600;color:<?php echo ( $t123_quota['remaining'] <= 0 ) ? '#d63638' : '#008a20'; ?>;">
                                                        <?php echo esc_html( number_format_i18n( $t123_quota['remaining'] ) ); ?> / <?php echo esc_html( number_format_i18n( $t123_quota['total'] ) ); ?>
                                                    </span> shipments remaining
                                                <?php else : ?>
                                                    <span style="color:#646970;">Unknown</span>
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                        <button type="button" class="button button-small" id="wcotl-t123-refresh-btn" onclick="wcotlRefreshTrack123Quota(this)">
                                            ↻ Refresh Quota
                                        </button>
                                    </div>
                                    <?php if ( isset( $t123_quota['remaining'], $t123_quota['total'] ) && $t123_quota['total'] > 0 ) :
                                        $pct = max( 0, min( 100, (int) round( ( $t123_quota['remaining'] / $t123_quota['total'] ) * 100 ) ) );
                                        $bar_color = ( $pct > 20 ) ? '#008a20' : ( ( $pct > 5 ) ? '#dba617' : '#d63638' );
                                    ?>
                                        <div id="wcotl-t123-progress-bar-wrap" style="margin-top:8px;background:#e2e4e7;border-radius:3px;height:6px;overflow:hidden;position:relative;">
                                            <div id="wcotl-t123-progress-bar" style="width:<?php echo $pct; ?>%;background:<?php echo $bar_color; ?>;height:100%;"></div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Packlink PRO -->
                        <div class="wcotl-settings-card" id="provider-packlink">
                            <h2>
                                <span class="dashicons dashicons-cart" style="color:#d63638;"></span>
                                Packlink PRO API
                                <?php if ( ! $packlink_active ) : ?>
                                    <span class="wcotl-badge-pill wcotl-badge-warning" style="margin-left:auto;">Plugin Inactive</span>
                                <?php elseif ( $packlink_api_key ) : ?>
                                    <span class="wcotl-badge-pill wcotl-badge-success" style="margin-left:auto;">✓ Configured</span>
                                <?php endif; ?>
                            </h2>

                            <?php if ( ! $packlink_active ) : ?>
                                <div class="wcotl-notice-callout warning">
                                    <strong>⚠ Packlink PRO Shipping plugin not detected:</strong>
                                    The Packlink PRO tracking provider requires the official
                                    <a href="https://wordpress.org/plugins/packlink-pro-shipping/" target="_blank">Packlink PRO Shipping for WooCommerce ↗</a>
                                    plugin to be installed and active.
                                </div>
                            <?php else : ?>
                                <p class="card-description">
                                    Enables auto-tracking using Packlink <strong>Shipment References</strong> (e.g. <code>ES2023...</code>) directly from your Packlink PRO account.
                                    Obtain your API key from the <a href="https://pro.packlink.com" target="_blank">Packlink PRO Dashboard (Settings → Packlink PRO API) ↗</a>.
                                </p>

                                <div class="wcotl-field-group">
                                    <label class="field-label" for="wcotl_packlink_api_key">Packlink PRO API Key</label>
                                    <div class="wcotl-input-with-button">
                                        <input type="password" id="wcotl_packlink_api_key" name="wcotl_packlink_api_key"
                                               value="<?php echo esc_attr( $packlink_api_key ); ?>"
                                               placeholder="Paste your Packlink PRO API key">
                                        <button type="button" class="button" onclick="wcotlToggleField('wcotl_packlink_api_key', this)">Show</button>
                                    </div>
                                    <div class="wcotl-field-help">Leave empty to disable Packlink auto-tracking.</div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- FedEx REST API -->
                        <div class="wcotl-settings-card" id="provider-fedex">
                            <h2>
                                <span class="dashicons dashicons-airplane" style="color:#7832ba;"></span>
                                FedEx REST API
                                <?php if ( $fedex_configured ) : ?>
                                    <span class="wcotl-badge-pill wcotl-badge-success" style="margin-left:auto;">✓ Configured (<?php echo esc_html( ucfirst( $fedex_environment ) ); ?>)</span>
                                <?php endif; ?>
                            </h2>
                            <p class="card-description">
                                Direct integration with the FedEx Track API via OAuth 2.0 client credentials.
                                Create an organization project and obtain your credentials in the <a href="https://developer.fedex.com" target="_blank">FedEx Developer Portal ↗</a>.
                            </p>

                            <div class="wcotl-field-group">
                                <label class="field-label" for="wcotl_fedex_client_id">FedEx API Key / Client ID</label>
                                <input type="text" id="wcotl_fedex_client_id" name="wcotl_fedex_client_id"
                                       value="<?php echo esc_attr( $fedex_client_id ); ?>"
                                       placeholder="e.g. l7xxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
                                       style="max-width:580px;font-family:monospace;width:100%;">
                            </div>

                            <div class="wcotl-field-group">
                                <label class="field-label" for="wcotl_fedex_client_secret">FedEx Secret Key / Client Secret</label>
                                <div class="wcotl-input-with-button">
                                    <input type="password" id="wcotl_fedex_client_secret" name="wcotl_fedex_client_secret"
                                           value="<?php echo esc_attr( $fedex_client_secret ); ?>"
                                           placeholder="Paste your FedEx Secret Key">
                                    <button type="button" class="button" onclick="wcotlToggleField('wcotl_fedex_client_secret', this)">Show</button>
                                </div>
                                <div class="wcotl-field-help">Used to obtain OAuth 2.0 Bearer tokens for FedEx REST tracking.</div>
                            </div>

                            <div class="wcotl-field-group">
                                <label class="field-label" for="wcotl_fedex_environment">Environment</label>
                                <select id="wcotl_fedex_environment" name="wcotl_fedex_environment" style="width:260px;">
                                    <option value="production" <?php selected( $fedex_environment, 'production' ); ?>>Production (apis.fedex.com)</option>
                                    <option value="sandbox" <?php selected( $fedex_environment, 'sandbox' ); ?>>Sandbox (apis-sandbox.fedex.com)</option>
                                </select>
                                <div class="wcotl-field-help">Use Sandbox for development and test tracking numbers; select Production for live customer parcels.</div>
                            </div>
                        </div>

                        <div class="wcotl-footer-actions">
                            <button type="submit" class="button button-primary button-hero">
                                <?php esc_html_e( 'Save Tracking Providers', 'wc-order-timeline' ); ?>
                            </button>
                        </div>
                    </form>

                <!-- ============================================================== -->
                <!-- TAB 2: AUTO-SYNC ENGINE                                        -->
                <!-- ============================================================== -->
                <?php elseif ( $current_tab === 'sync' ) : ?>

                    <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <?php wp_nonce_field( 'wcotl_settings_nonce' ); ?>
                        <input type="hidden" name="action" value="wcotl_save_settings">
                        <input type="hidden" name="current_tab" value="sync">

                        <div class="wcotl-settings-card">
                            <h2>
                                <span class="dashicons dashicons-update" style="color:#2271b1;"></span>
                                <?php esc_html_e( 'Background Synchronization Engine', 'wc-order-timeline' ); ?>
                            </h2>
                            <p class="card-description">
                                Configure the background WP-Cron engine (<code>wcotl_auto_sync</code>) that polls active tracking providers (17TRACK, Track123, Packlink, FedEx) to ingest new status updates.
                            </p>

                            <div class="wcotl-field-group">
                                <label class="field-label" for="wcotl_sync_interval">
                                    <?php esc_html_e( 'Sync Polling Interval (hours)', 'wc-order-timeline' ); ?>
                                </label>
                                <input type="number" id="wcotl_sync_interval" name="wcotl_sync_interval"
                                       value="<?php echo esc_attr( $sync_interval ); ?>"
                                       min="1" max="24" step="1" style="width:120px;">
                                <div class="wcotl-field-help">
                                    Frequency in hours between background checks. Default is <code>1</code> hour.
                                    <?php if ( $next_sync ) :
                                        $time_diff = human_time_diff( time(), $next_sync );
                                    ?>
                                        <br><strong>Next Scheduled Run:</strong> <?php echo esc_html( date_i18n( get_option('date_format') . ' ' . get_option('time_format'), $next_sync ) ); ?>
                                        <span class="wcotl-badge-pill wcotl-badge-neutral" style="margin-left:4px;">in <?php echo esc_html( $time_diff ); ?></span>
                                    <?php else : ?>
                                        <br><span style="color:#d63638;">⚠ No background cron schedule currently active. Saving settings will reschedule it automatically.</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="wcotl-field-group" style="margin-top:20px;">
                                <label class="field-label" for="wcotl_inactivity_days">
                                    <?php esc_html_e( 'Inactivity Threshold Before Stopping (days)', 'wc-order-timeline' ); ?>
                                </label>
                                <input type="number" id="wcotl_inactivity_days" name="wcotl_inactivity_days"
                                       value="<?php echo esc_attr( $inactivity_days ); ?>"
                                       min="1" max="365" step="1" style="width:120px;">
                                <div class="wcotl-field-help">
                                    If a carrier returns no status changes for this number of consecutive days, automated polling ceases to conserve API quota.
                                    An email notification is automatically dispatched to the site administrator (<code><?php echo esc_html( $admin_email ); ?></code>).
                                </div>
                            </div>
                        </div>

                        <!-- How Auto-Sync Operates -->
                        <div class="wcotl-settings-card" style="background:#f9fbfd;border-color:#d0e2f5;">
                            <h2 style="color:#1d2327;">
                                <span class="dashicons dashicons-info" style="color:#2271b1;"></span>
                                <?php esc_html_e( 'Automation Rules & Milestone Handling', 'wc-order-timeline' ); ?>
                            </h2>
                            <ul style="font-size:13px;line-height:1.8;color:#2c3338;margin:12px 0 0 18px;list-style:disc;">
                                <li><strong>Preserved Manual History:</strong> Existing manual steps and preset milestones are <em>never</em> overwritten, modified, or deleted. Automatic provider scans are appended seamlessly.</li>
                                <li><strong>Automated Delivery Finalization:</strong> When the carrier flags the parcel as <code>Delivered</code>, the actual delivery timestamp is recorded automatically in the order meta.</li>
                                <li><strong>Smart Quota Cease:</strong> Background queries automatically cease for shipments that are marked <code>Delivered</code>, <code>Expired</code>, or have exceeded the inactivity threshold.</li>
                                <li><strong>Instant Sync &amp; Resume:</strong> On any tracking code detail screen, administrators can click <em>Sync Now</em> for immediate live queries or resume stopped tracking with one click.</li>
                            </ul>
                        </div>

                        <div class="wcotl-footer-actions">
                            <button type="submit" class="button button-primary button-hero">
                                <?php esc_html_e( 'Save Sync Settings', 'wc-order-timeline' ); ?>
                            </button>
                        </div>
                    </form>

                <!-- ============================================================== -->
                <!-- TAB 3: RATE COMPARATOR                                         -->
                <!-- ============================================================== -->
                <?php elseif ( $current_tab === 'rates' ) : ?>

                    <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <?php wp_nonce_field( 'wcotl_settings_nonce' ); ?>
                        <input type="hidden" name="action" value="wcotl_save_settings">
                        <input type="hidden" name="current_tab" value="rates">

                        <!-- Paccofacile.it API -->
                        <div class="wcotl-settings-card">
                            <h2>
                                <span class="dashicons dashicons-money-alt" style="color:#008a20;"></span>
                                Paccofacile.it API
                                <?php if ( $paccofacile_configured ) : ?>
                                    <span class="wcotl-badge-pill wcotl-badge-success" style="margin-left:auto;">✓ Configured</span>
                                <?php endif; ?>
                            </h2>
                            <p class="card-description">
                                Connect your <a href="https://www.paccofacile.it" target="_blank">Paccofacile.it ↗</a> PRO account to quote and compare live shipping rates alongside Packlink PRO directly on the order details screen.
                                Obtain your credentials under <strong>Paccofacile PRO &rarr; Integrazioni / API</strong>.
                            </p>

                            <?php if ( $paccofacile_plugin && empty( $paccofacile_acc ) && empty( $paccofacile_key ) && empty( $paccofacile_token ) ) : ?>
                                <div class="wcotl-notice-callout info">
                                    ℹ <strong>Paccofacile for WooCommerce detected:</strong> Credentials are automatically imported from your existing Paccofacile plugin settings when left blank below.
                                </div>
                            <?php endif; ?>

                            <div class="wcotl-field-group">
                                <label class="field-label" for="wcotl_paccofacile_account_number">Paccofacile Account Number</label>
                                <input type="text" id="wcotl_paccofacile_account_number" name="wcotl_paccofacile_account_number"
                                       value="<?php echo esc_attr( $paccofacile_acc ); ?>"
                                       placeholder="e.g. 12345"
                                       style="max-width:580px;font-family:monospace;width:100%;">
                            </div>

                            <div class="wcotl-field-group">
                                <label class="field-label" for="wcotl_paccofacile_api_key">Paccofacile API Key</label>
                                <input type="text" id="wcotl_paccofacile_api_key" name="wcotl_paccofacile_api_key"
                                       value="<?php echo esc_attr( $paccofacile_key ); ?>"
                                       placeholder="e.g. pf_live_xxxxxxxxxxxxxxxxxxxxxxxx"
                                       style="max-width:580px;font-family:monospace;width:100%;">
                            </div>

                            <div class="wcotl-field-group">
                                <label class="field-label" for="wcotl_paccofacile_token">Paccofacile Bearer Token</label>
                                <div class="wcotl-input-with-button">
                                    <input type="password" id="wcotl_paccofacile_token" name="wcotl_paccofacile_token"
                                           value="<?php echo esc_attr( $paccofacile_token ); ?>"
                                           placeholder="Paste your Paccofacile Bearer Token">
                                    <button type="button" class="button" onclick="wcotlToggleField('wcotl_paccofacile_token', this)">Show</button>
                                </div>
                            </div>

                            <div class="wcotl-field-group">
                                <label class="field-label" for="wcotl_paccofacile_environment">Environment</label>
                                <select id="wcotl_paccofacile_environment" name="wcotl_paccofacile_environment" style="width:260px;">
                                    <option value="live" <?php selected( $paccofacile_env, 'live' ); ?>>Production (Live)</option>
                                    <option value="sandbox" <?php selected( $paccofacile_env, 'sandbox' ); ?>>Sandbox (Testing)</option>
                                </select>
                            </div>
                        </div>

                        <!-- Packlink PRO Quoting Status Card -->
                        <div class="wcotl-settings-card">
                            <h2>
                                <span class="dashicons dashicons-cart" style="color:#2271b1;"></span>
                                Packlink PRO Quoting Status
                            </h2>
                            <p class="card-description">
                                Live quote comparisons require an active Packlink PRO account key.
                            </p>
                            <?php if ( ! empty( $packlink_api_key ) && $packlink_active ) : ?>
                                <div class="wcotl-notice-callout info" style="margin:0;">
                                    ✓ <strong>Packlink PRO Quoting Active:</strong> Using API credentials configured in the <a href="<?php echo esc_url( admin_url('admin.php?page=wcotl-settings&tab=providers#provider-packlink') ); ?>">Tracking Providers</a> tab.
                                </div>
                            <?php else : ?>
                                <div class="wcotl-notice-callout warning" style="margin:0;">
                                    ⚠ <strong>Packlink PRO API is not ready:</strong>
                                    <?php if ( ! $packlink_active ) : ?>
                                        Packlink PRO Shipping plugin is not active.
                                    <?php else : ?>
                                        Packlink API key is missing.
                                    <?php endif; ?>
                                    <a href="<?php echo esc_url( admin_url('admin.php?page=wcotl-settings&tab=providers#provider-packlink') ); ?>" class="button button-small" style="margin-left:8px;">
                                        Configure Packlink &rarr;
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Fallback Package Dimensions -->
                        <div class="wcotl-settings-card">
                            <h2>
                                <span class="dashicons dashicons-archive" style="color:#d63638;"></span>
                                <?php esc_html_e( 'Default Package Fallback Dimensions', 'wc-order-timeline' ); ?>
                            </h2>
                            <p class="card-description">
                                Used to pre-populate shipment parameters when order products lack specific dimensions or weight in their WooCommerce product settings.
                            </p>

                            <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(130px, 1fr));gap:14px;max-width:680px;">
                                <div>
                                    <label class="field-label" for="wcotl_default_package_weight">Weight (kg)</label>
                                    <input type="number" step="0.01" min="0.01" id="wcotl_default_package_weight" name="wcotl_default_package_weight"
                                           value="<?php echo esc_attr( $default_package_weight ); ?>" style="width:100%;">
                                </div>
                                <div>
                                    <label class="field-label" for="wcotl_default_package_length">Length (cm)</label>
                                    <input type="number" step="1" min="1" id="wcotl_default_package_length" name="wcotl_default_package_length"
                                           value="<?php echo esc_attr( $default_package_length ); ?>" style="width:100%;">
                                </div>
                                <div>
                                    <label class="field-label" for="wcotl_default_package_width">Width (cm)</label>
                                    <input type="number" step="1" min="1" id="wcotl_default_package_width" name="wcotl_default_package_width"
                                           value="<?php echo esc_attr( $default_package_width ); ?>" style="width:100%;">
                                </div>
                                <div>
                                    <label class="field-label" for="wcotl_default_package_height">Height (cm)</label>
                                    <input type="number" step="1" min="1" id="wcotl_default_package_height" name="wcotl_default_package_height"
                                           value="<?php echo esc_attr( $default_package_height ); ?>" style="width:100%;">
                                </div>
                            </div>
                        </div>

                        <div class="wcotl-footer-actions">
                            <button type="submit" class="button button-primary button-hero">
                                <?php esc_html_e( 'Save Rate Comparator Settings', 'wc-order-timeline' ); ?>
                            </button>
                        </div>
                    </form>

                <!-- ============================================================== -->
                <!-- TAB 4: SECURITY & ANTI-SCRAPING                                -->
                <!-- ============================================================== -->
                <?php elseif ( $current_tab === 'security' ) : ?>

                    <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <?php wp_nonce_field( 'wcotl_settings_nonce' ); ?>
                        <input type="hidden" name="action" value="wcotl_save_settings">
                        <input type="hidden" name="current_tab" value="security">

                        <!-- Cloudflare Turnstile -->
                        <div class="wcotl-settings-card">
                            <h2>
                                <span class="dashicons dashicons-shield" style="color:#f48120;"></span>
                                Cloudflare Turnstile CAPTCHA Protection
                                <?php if ( $turnstile_configured ) : ?>
                                    <span class="wcotl-badge-pill wcotl-badge-success" style="margin-left:auto;">✓ Active &amp; Enforcing</span>
                                <?php else : ?>
                                    <span class="wcotl-badge-pill wcotl-badge-neutral" style="margin-left:auto;">Optional (Inactive)</span>
                                <?php endif; ?>
                            </h2>
                            <p class="card-description">
                                Safeguard public tracking search lookups (<code>[wc_order_timeline_tracking]</code>) from automated bots, scraping, and enumeration attacks without friction for real customers.
                                Generate keys in the <a href="https://dash.cloudflare.com/?to=/:account/turnstile" target="_blank">Cloudflare Dashboard ↗</a>.
                            </p>

                            <div class="wcotl-field-group">
                                <label class="field-label" for="wcotl_turnstile_site_key">Cloudflare Turnstile Site Key</label>
                                <input type="text" id="wcotl_turnstile_site_key" name="wcotl_turnstile_site_key"
                                       value="<?php echo esc_attr( $turnstile_site_key ); ?>"
                                       placeholder="e.g. 0x4AAAAAA..."
                                       style="max-width:580px;font-family:monospace;width:100%;">
                            </div>

                            <div class="wcotl-field-group">
                                <label class="field-label" for="wcotl_turnstile_secret_key">Cloudflare Turnstile Secret Key</label>
                                <div class="wcotl-input-with-button">
                                    <input type="password" id="wcotl_turnstile_secret_key" name="wcotl_turnstile_secret_key"
                                           value="<?php echo esc_attr( $turnstile_secret_key ); ?>"
                                           placeholder="e.g. 0x4AAAAAA...">
                                    <button type="button" class="button" onclick="wcotlToggleField('wcotl_turnstile_secret_key', this)">Show</button>
                                </div>
                                <div class="wcotl-field-help">Used to securely verify Turnstile challenge tokens server-side upon customer lookup.</div>
                            </div>

                            <div class="wcotl-notice-callout info">
                                💡 <strong>Tip:</strong> In your Cloudflare dashboard, configure your Turnstile widget with <strong>Managed</strong> or <strong>Non-interactive</strong> mode for completely seamless customer verification.
                            </div>
                        </div>

                        <!-- Frontend Rate Limiting -->
                        <div class="wcotl-settings-card">
                            <h2>
                                <span class="dashicons dashicons-clock" style="color:#2271b1;"></span>
                                Frontend IP Rate Limiting
                            </h2>
                            <p class="card-description">
                                Protect against brute-force query attempts. Limits how many tracking code lookups can be made from a single IP address per minute.
                            </p>

                            <div class="wcotl-field-group">
                                <label class="field-label" for="wcotl_rate_limit_max_requests">Max Requests Per Minute Per IP</label>
                                <input type="number" id="wcotl_rate_limit_max_requests" name="wcotl_rate_limit_max_requests"
                                       value="<?php echo esc_attr( $rate_limit_max ); ?>"
                                       min="1" max="120" step="1" style="width:120px;">
                                <div class="wcotl-field-help">Default: <code>15</code> requests/min. Excess requests are throttled with a 429 response.</div>
                            </div>
                        </div>

                        <div class="wcotl-footer-actions">
                            <button type="submit" class="button button-primary button-hero">
                                <?php esc_html_e( 'Save Security Settings', 'wc-order-timeline' ); ?>
                            </button>
                        </div>
                    </form>

                <!-- ============================================================== -->
                <!-- TAB 5: GENERAL & SYSTEM                                        -->
                <!-- ============================================================== -->
                <?php elseif ( $current_tab === 'system' ) :
                    global $wpdb;
                    $table_timeline = $wpdb->prefix . 'order_timeline';
                    $table_meta     = $wpdb->prefix . 'order_timeline_meta';
                    $table_presets  = $wpdb->prefix . 'order_timeline_presets';

                    $count_steps = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_timeline" );
                    $count_meta  = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT tracking_code) FROM $table_meta" );
                    $count_presets = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_presets" );
                ?>

                    <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <?php wp_nonce_field( 'wcotl_settings_nonce' ); ?>
                        <input type="hidden" name="action" value="wcotl_save_settings">
                        <input type="hidden" name="current_tab" value="system">
                        <input type="hidden" name="wcotl_save_system_settings" value="1">

                        <!-- Data Retention & Uninstall -->
                        <div class="wcotl-settings-card">
                            <h2>
                                <span class="dashicons dashicons-trash" style="color:#d63638;"></span>
                                <?php esc_html_e( 'Data Retention & Uninstall Preferences', 'wc-order-timeline' ); ?>
                            </h2>
                            <p class="card-description">
                                Choose how the plugin handles database tables and stored tracking history when uninstalled.
                            </p>

                            <div class="wcotl-field-group">
                                <label style="font-weight:normal;display:flex;align-items:flex-start;gap:10px;cursor:pointer;">
                                    <input type="checkbox" name="wcotl_delete_data_on_uninstall" value="1" <?php checked( $delete_data_on_uninstall, 1 ); ?> style="margin-top:3px;">
                                    <span>
                                        <strong style="color:#1d2327;display:block;"><?php esc_html_e( 'Erase all plugin data upon uninstall', 'wc-order-timeline' ); ?></strong>
                                        <span class="wcotl-field-help" style="margin-top:2px;display:block;">
                                            If enabled, all custom database tables (<code><?php echo esc_html( $table_timeline ); ?></code>, <code><?php echo esc_html( $table_meta ); ?></code>, <code><?php echo esc_html( $table_presets ); ?></code>) and plugin options will be permanently dropped when deleting the plugin from WordPress. If unchecked (default), all data is safely preserved.
                                        </span>
                                    </span>
                                </label>
                            </div>
                        </div>

                        <!-- System Status & Diagnostics -->
                        <div class="wcotl-settings-card">
                            <h2>
                                <span class="dashicons dashicons-admin-tools" style="color:#2271b1;"></span>
                                <?php esc_html_e( 'System Status & Diagnostics', 'wc-order-timeline' ); ?>
                            </h2>
                            <p class="card-description">
                                Overview of environment versions and database table statistics.
                            </p>

                            <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:12px;">
                                <div style="background:#f6f7f7;padding:12px;border-radius:4px;border:1px solid #dcdcde;">
                                    <div style="font-size:11px;color:#646970;">WordPress / PHP</div>
                                    <div style="font-size:14px;font-weight:600;color:#1d2327;margin-top:2px;">
                                        WP <?php echo esc_html( get_bloginfo( 'version' ) ); ?> &bull; PHP <?php echo esc_html( PHP_VERSION ); ?>
                                    </div>
                                </div>

                                <div style="background:#f6f7f7;padding:12px;border-radius:4px;border:1px solid #dcdcde;">
                                    <div style="font-size:11px;color:#646970;">WooCommerce</div>
                                    <div style="font-size:14px;font-weight:600;color:#1d2327;margin-top:2px;">
                                        v<?php echo defined('WC_VERSION') ? esc_html( WC_VERSION ) : 'Unknown'; ?>
                                    </div>
                                </div>

                                <div style="background:#f6f7f7;padding:12px;border-radius:4px;border:1px solid #dcdcde;">
                                    <div style="font-size:11px;color:#646970;">Database Records</div>
                                    <div style="font-size:13px;font-weight:600;color:#1d2327;margin-top:2px;">
                                        <?php echo esc_html( number_format_i18n( $count_meta ) ); ?> codes &bull; <?php echo esc_html( number_format_i18n( $count_steps ) ); ?> steps
                                    </div>
                                </div>

                                <div style="background:#f6f7f7;padding:12px;border-radius:4px;border:1px solid #dcdcde;">
                                    <div style="font-size:11px;color:#646970;">Presets &amp; Milestones</div>
                                    <div style="font-size:13px;font-weight:600;color:#1d2327;margin-top:2px;">
                                        <?php echo esc_html( number_format_i18n( $count_presets ) ); ?> configured presets
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Frontend Shortcode Reference -->
                        <div class="wcotl-settings-card">
                            <h2>
                                <span class="dashicons dashicons-shortcode" style="color:#0073aa;"></span>
                                <?php esc_html_e( 'Frontend Tracking Shortcode', 'wc-order-timeline' ); ?>
                            </h2>
                            <p class="card-description">
                                Place the tracking lookup form on any page or post so customers can track their orders:
                            </p>

                            <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;">
                                <code id="wcotl-shortcode-snippet" style="font-size:14px;padding:6px 12px;background:#f0f0f1;border:1px solid #c3c4c7;border-radius:4px;">[wc_order_timeline_tracking]</code>
                                <button type="button" class="button" onclick="wcotlCopyText('[wc_order_timeline_tracking]', this)">Copy Shortcode</button>
                            </div>
                            <div class="wcotl-field-help">
                                Direct lookup URL parameter format: <code><?php echo esc_url( home_url( '/tracking/?tracking=TRK-XXXX-YYYY' ) ); ?></code>
                            </div>
                        </div>

                        <div class="wcotl-footer-actions">
                            <button type="submit" class="button button-primary button-hero">
                                <?php esc_html_e( 'Save General Settings', 'wc-order-timeline' ); ?>
                            </button>
                        </div>
                    </form>

                <?php endif; ?>

            </div>
        </div>

        <script>
        function wcotlToggleField(fieldId, btn) {
            var field = document.getElementById(fieldId);
            if (!field) return;
            if (field.type === 'password') {
                field.type = 'text';
                btn.textContent = 'Hide';
            } else {
                field.type = 'password';
                btn.textContent = 'Show';
            }
        }

        function wcotlCopyText(text, btn) {
            navigator.clipboard.writeText(text).then(function() {
                var prevText = btn.textContent;
                btn.textContent = '✓ Copied!';
                setTimeout(function() { btn.textContent = prevText; }, 2000);
            });
        }

        function wcotlRefreshTrack123Quota(btn) {
            var disp = document.getElementById('wcotl-t123-quota-display');
            var bar  = document.getElementById('wcotl-t123-progress-bar');
            if (btn) btn.disabled = true;
            if (disp) disp.innerHTML = '<span style="color:#646970;">⏳ Refreshing...</span>';

            var params = new URLSearchParams();
            params.append('action', 'wcotl_get_provider_quota');
            params.append('nonce', <?php echo json_encode( wp_create_nonce( 'wcotl_admin_nonce' ) ); ?>);
            params.append('provider', 'track123');
            params.append('force_refresh', '1');

            fetch(ajaxurl, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: params
            })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (btn) btn.disabled = false;
                if (res.success && res.data) {
                    var d = res.data;
                    if (d.error) {
                        disp.innerHTML = '<span style="color:#d63638;">' + d.error + '</span>';
                    } else if (d.remaining !== null && d.total !== null) {
                        var color = d.remaining <= 0 ? '#d63638' : '#008a20';
                        disp.innerHTML = '<span style="font-weight:600;color:' + color + ';">' +
                            Number(d.remaining).toLocaleString() + ' / ' + Number(d.total).toLocaleString() +
                            '</span> shipments remaining';
                        if (bar && d.total > 0) {
                            var pct = Math.max(0, Math.min(100, Math.round((d.remaining / d.total) * 100)));
                            var barColor = pct > 20 ? '#008a20' : (pct > 5 ? '#dba617' : '#d63638');
                            bar.style.width = pct + '%';
                            bar.style.background = barColor;
                        }
                    }
                } else {
                    disp.innerHTML = '<span style="color:#d63638;">Failed to load quota</span>';
                }
            })
            .catch(function() {
                if (btn) btn.disabled = false;
                disp.innerHTML = '<span style="color:#d63638;">Network error</span>';
            });
        }
        </script>
        <?php
    }

    /* ------------------------------------------------------------------
     * Modal on plugins.php when user clicks "Delete"
     * ------------------------------------------------------------------ */

    public static function render_plugins_uninstall_modal(): void {
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
                var deleteData = checkbox.checked ? '1' : '0';
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
