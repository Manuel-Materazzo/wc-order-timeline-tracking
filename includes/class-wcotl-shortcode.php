<?php
/**
 * Frontend shortcode
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class WCOTL_Shortcode {

    public static function init() {
        add_shortcode( 'wc_order_timeline_tracking', array( __CLASS__, 'render' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
    }

    /**
     * Register frontend assets for the tracking shortcode.
     */
    public static function register_assets() {
        wp_register_style(
            'wcotl-frontend',
            WCOTL_PLUGIN_URL . 'assets/css/wcotl-frontend.css',
            array(),
            WCOTL_VERSION
        );
    }

    /**
     * Get client IP address safely.
     *
     * @return string
     */
    public static function get_client_ip() {
        $ip = '';
        if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
            $ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
        } elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            $forwarded = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
            $ip = trim( $forwarded[0] );
        } elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
            $ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
        }

        return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '127.0.0.1';
    }

    /**
     * Verify Cloudflare Turnstile token via siteverify API endpoint.
     *
     * @param string $token
     * @param string $client_ip
     * @return bool
     */
    public static function verify_turnstile( $token, $client_ip = '' ) {
        $secret_key = get_option( 'wcotl_turnstile_secret_key', '' );
        if ( empty( $secret_key ) || empty( $token ) ) {
            return false;
        }

        $response = wp_remote_post( 'https://challenges.cloudflare.com/turnstile/v0/siteverify', [
            'timeout' => 10,
            'body'    => [
                'secret'   => $secret_key,
                'response' => $token,
                'remoteip' => $client_ip,
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        return ! empty( $data['success'] );
    }

    /**
     * Check if client IP has exceeded the allowed rate limit window.
     *
     * @param string $client_ip
     * @param int    $max_requests
     * @return bool True if rate limited, false otherwise.
     */
    public static function check_rate_limit( $client_ip, $max_requests = 15 ) {
        $transient_key = 'wcotl_rate_' . md5( $client_ip );
        $current_count = (int) get_transient( $transient_key );

        $current_count++;
        set_transient( $transient_key, $current_count, 60 );

        return ( $current_count > $max_requests );
    }

    /**
     * Reset rate limit transient for a client IP upon successful challenge completion.
     *
     * @param string $client_ip
     */
    public static function reset_rate_limit( $client_ip ) {
        $transient_key = 'wcotl_rate_' . md5( $client_ip );
        delete_transient( $transient_key );
    }

    public static function render() {
        ob_start();

        $site_key         = get_option( 'wcotl_turnstile_site_key', '' );
        $secret_key       = get_option( 'wcotl_turnstile_secret_key', '' );
        $turnstile_active = ( ! empty( $site_key ) && ! empty( $secret_key ) );
        $max_requests     = max( 1, (int) get_option( 'wcotl_rate_limit_max_requests', 15 ) );
        $client_ip        = self::get_client_ip();

        $code             = isset( $_GET['tracking'] ) ? sanitize_text_field( wp_unslash( $_GET['tracking'] ) ) : '';
        $turnstile_token  = isset( $_REQUEST['cf-turnstile-response'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['cf-turnstile-response'] ) ) : '';
        $steps            = [];
        $meta             = null;
        $error            = '';
        $show_turnstile   = false;

        $transient_key   = 'wcotl_rate_' . md5( $client_ip );
        $current_count   = (int) get_transient( $transient_key );
        $is_rate_limited = ( $current_count >= $max_requests );

        if ( $code !== '' ) {
            if ( $turnstile_active && ! empty( $turnstile_token ) ) {
                $is_valid = self::verify_turnstile( $turnstile_token, $client_ip );
                if ( $is_valid ) {
                    self::reset_rate_limit( $client_ip );
                    $is_rate_limited = false;
                } else {
                    $error          = __( 'Security verification failed. Please complete the challenge and try again.', 'wc-order-timeline' );
                    $show_turnstile = true;
                }
            } else {
                $current_count++;
                set_transient( $transient_key, $current_count, 60 );
                $is_rate_limited = ( $current_count > $max_requests );

                if ( $is_rate_limited ) {
                    if ( $turnstile_active ) {
                        $error          = __( 'Security verification required. Please complete the challenge below before searching.', 'wc-order-timeline' );
                        $show_turnstile = true;
                    } else {
                        $error          = __( 'Too many tracking requests. Please wait a minute and try again.', 'wc-order-timeline' );
                    }
                }
            }

            if ( empty( $error ) ) {
                global $wpdb;
                $table = $wpdb->prefix . 'order_timeline';

                $steps = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT * FROM {$table} WHERE tracking_code = %s ORDER BY step_date ASC",
                        $code
                    )
                );

                if ( empty( $steps ) ) {
                    $error = sprintf(
                        /* translators: %s is the tracking code */
                        __( 'No order found for tracking code <strong>%s</strong>. Please verify the code and try again.', 'wc-order-timeline' ),
                        esc_html( $code )
                    );
                }
            }
        } elseif ( $is_rate_limited && $turnstile_active ) {
            $show_turnstile = true;
        }

        $estimated_delivery = ( $code !== '' && empty( $error ) ) ? WCOTL_DB::get_meta( $code, 'estimated_delivery' ) : null;
        $delivered_at       = ( $code !== '' && empty( $error ) ) ? WCOTL_DB::get_meta( $code, 'delivered_at' )       : null;
        wp_enqueue_style( 'wcotl-frontend' );

        // SVG icon map
        $icons = WCOTL_Icons::map();
        ?>
        <div class="wcotl-wrap">
        <div class="wcotl-container">

            <div class="wcotl-header">
                <span class="wcotl-header__eyebrow"><?php esc_html_e( 'Shipment & Delivery', 'wc-order-timeline' ); ?></span>
                <h1><?php esc_html_e( 'Track your order', 'wc-order-timeline' ); ?></h1>
                <p><?php esc_html_e( 'Insert the tracking code received via email.', 'wc-order-timeline' ); ?></p>
            </div>

            <div class="wcotl-search">
                <label for="wcotl-input"><?php esc_html_e( 'Tracking code', 'wc-order-timeline' ); ?></label>
                <form method="GET" action="">
                    <?php
                    // Preserva gli altri parametri GET (es. page id in WP)
                    foreach ( $_GET as $k => $v ) {
                        if ( $k === 'tracking' || $k === 'cf-turnstile-response' ) continue;
                        echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
                    }
                    ?>
                    <div class="wcotl-search-row">
                        <input
                            type="text"
                            id="wcotl-input"
                            name="tracking"
                            placeholder="<?php echo esc_attr__( 'e.g. TRK-20240518-001', 'wc-order-timeline' ); ?>"
                            value="<?php echo esc_attr( $code ); ?>"
                            autocomplete="off"
                            spellcheck="false"
                        >
                        <button type="submit"><?php esc_html_e( 'Search', 'wc-order-timeline' ); ?></button>
                    </div>
                    <?php if ( $show_turnstile && $turnstile_active ) : ?>
                        <div class="wcotl-turnstile-wrap" style="margin-top:14px;display:flex;justify-content:center;">
                            <div class="cf-turnstile" data-sitekey="<?php echo esc_attr( $site_key ); ?>" data-theme="auto"></div>
                        </div>
                        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
                    <?php endif; ?>
                </form>
            </div>

            <?php if ( $error ) : ?>
                <div class="wcotl-error"><?php echo wp_kses_post( $error ); ?></div>
            <?php endif; ?>

            <?php if ( ! empty( $steps ) ) : ?>
                <div class="wcotl-tracking-badge">
                    <span class="wcotl-tracking-badge__label"><?php esc_html_e( 'Code', 'wc-order-timeline' ); ?></span>
                    <span class="wcotl-tracking-badge__code"><?php echo esc_html( $code ); ?></span>
                </div>

                <?php if ( ! empty( $delivered_at ) ) :
                    $dd = DateTime::createFromFormat( 'Y-m-d', $delivered_at );
                    $dd_label = $dd ? $dd->format('d/m/Y') : esc_html( $delivered_at );
                ?>
                <div class="wcotl-delivered">
                    <div class="wcotl-delivered__icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    </div>
                    <div>
                        <div class="wcotl-delivered__label"><?php esc_html_e( 'Delivered', 'wc-order-timeline' ); ?></div>
                        <div class="wcotl-delivered__date"><?php echo esc_html( $dd_label ); ?></div>
                    </div>
                </div>
                <?php elseif ( ! empty( $estimated_delivery ) ) :
                    $ed = DateTime::createFromFormat( 'Y-m-d', $estimated_delivery );
                    $ed_label = $ed ? $ed->format('d/m/Y') : esc_html( $estimated_delivery );
                ?>
                <div class="wcotl-delivery">
                    <div class="wcotl-delivery__icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    </div>
                    <div>
                        <div class="wcotl-delivery__label"><?php esc_html_e( 'Estimated delivery', 'wc-order-timeline' ); ?></div>
                        <div class="wcotl-delivery__date"><?php echo esc_html( $ed_label ); ?></div>
                    </div>
                </div>
                <?php endif; ?>

                <ul class="wcotl-timeline">
                    <?php foreach ( $steps as $i => $step ) :
                        $icon_key  = sanitize_key( $step->step_icon ?: 'truck' );
                        $icon_svg  = isset( $icons[ $icon_key ] ) ? $icons[ $icon_key ] : $icons['truck'];
                        $delay     = $i * 80; // ms
                        $dt        = new DateTime( $step->step_date );
                        $date_fmt  = $dt->format( 'd/m/Y' );
                        $time_fmt  = $dt->format( 'H:i' );
                        $is_voided = ! empty( $step->step_voided );
                        // "last active" = l'ultimo step NON annullato
                        $last_active = false;
                        foreach ( array_reverse( $steps ) as $ls ) {
                            if ( empty( $ls->step_voided ) ) { $last_active = ( $ls->id === $step->id ); break; }
                        }
                        $step_class = 'wcotl-step' . ( $is_voided ? ' wcotl-step--voided' : '' ) . ( $last_active ? ' wcotl-step--last' : '' );
                    ?>
                    <li class="<?php echo esc_attr( $step_class ); ?>" style="animation-delay:<?php echo $delay; ?>ms">
                        <div class="wcotl-step__dot">
                            <?php if ( $is_voided ) : ?>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                            <?php else : ?>
                            <?php echo $icon_svg; ?>
                            <?php endif; ?>
                        </div>
                        <div class="wcotl-step__body">
                            <div class="wcotl-step__date"><?php echo esc_html( $date_fmt ); ?> &nbsp;·&nbsp; <?php echo esc_html( $time_fmt ); ?></div>
                            <div class="wcotl-step__label"><?php echo esc_html( $step->step_label ); ?></div>
                            <?php if ( ! empty( $step->step_note ) ) : ?>
                                <div class="wcotl-step__note"><?php echo nl2br( esc_html( $step->step_note ) ); ?></div>
                            <?php endif; ?>
                            <?php if ( $is_voided ) : ?>
                                <div class="wcotl-step__void-badge">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="width:12px;height:12px;"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
                                    <?php esc_html_e( 'Unconfirmed information', 'wc-order-timeline' ); ?>
                                </div>
                                <?php if ( ! empty( $step->step_void_reason ) ) : ?>
                                    <div class="wcotl-step__void-reason"><?php echo nl2br( esc_html( $step->step_void_reason ) ); ?></div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

        </div>
    </div>
    <?php
    return ob_get_clean();
}

}

