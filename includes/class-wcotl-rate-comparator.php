<?php
/**
 * Shipping Rate Comparator (Packlink PRO vs Paccofacile.it)
 *
 * Compares live shipping rates across Packlink PRO and Paccofacile.it
 * for a specific WooCommerce order shipment.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WCOTL_Rate_Comparator {

    public static function init(): void {
        add_action( 'wp_ajax_wcotl_get_order_shipping_details', [ __CLASS__, 'ajax_get_order_shipping_details' ] );
        add_action( 'wp_ajax_wcotl_compare_shipping_rates',     [ __CLASS__, 'ajax_compare_shipping_rates' ] );
        add_action( 'admin_footer',                            [ __CLASS__, 'render_comparison_modal' ] );
    }

    /**
     * Get instance of Packlink provider for quoting.
     */
    public static function get_packlink_provider(): ?WCOTL_Packlink_Provider {
        $key = get_option( 'wcotl_packlink_api_key', '' );
        if ( empty( $key ) ) {
            return null;
        }
        return new WCOTL_Packlink_Provider( $key );
    }

    /**
     * Get instance of Paccofacile provider for quoting.
     */
    public static function get_paccofacile_provider(): WCOTL_Paccofacile_Provider {
        $acc   = get_option( 'wcotl_paccofacile_account_number', '' );
        $key   = get_option( 'wcotl_paccofacile_api_key', '' );
        $token = get_option( 'wcotl_paccofacile_token', '' );
        $env   = get_option( 'wcotl_paccofacile_environment', 'live' );

        return new WCOTL_Paccofacile_Provider( $acc, $key, $token, $env );
    }

    /**
     * Extract pre-filled origin, destination, and package estimates for a WooCommerce order.
     */
    public static function get_order_shipping_details( int $order_id ): ?array {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return null;
        }

        // Store origin address (WooCommerce settings)
        $origin = [
            'country'  => WC()->countries->get_base_country() ?: 'IT',
            'postcode' => WC()->countries->get_base_postcode() ?: '',
            'city'     => WC()->countries->get_base_city() ?: '',
            'state'    => WC()->countries->get_base_state() ?: '',
        ];

        // Customer destination address (order shipping with billing fallback)
        $destination = [
            'country'  => $order->get_shipping_country() ?: ( $order->get_billing_country() ?: 'IT' ),
            'postcode' => $order->get_shipping_postcode() ?: ( $order->get_billing_postcode() ?: '' ),
            'city'     => $order->get_shipping_city() ?: ( $order->get_billing_city() ?: '' ),
            'state'    => $order->get_shipping_state() ?: ( $order->get_billing_state() ?: '' ),
        ];

        // Calculate package weights & dimensions
        $total_weight_kg = 0.0;
        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            if ( $product && $product->has_weight() ) {
                $raw_w = (float) $product->get_weight();
                if ( function_exists( 'wc_get_weight' ) ) {
                    $w_kg = (float) wc_get_weight( $raw_w, 'kg' );
                } else {
                    $w_kg = $raw_w;
                }
                $total_weight_kg += $w_kg * max( 1, (int) $item->get_quantity() );
            }
        }

        $default_w = (float) get_option( 'wcotl_default_package_weight', 1.0 );
        if ( $total_weight_kg <= 0 ) {
            $total_weight_kg = max( 0.1, $default_w );
        } else {
            $total_weight_kg = round( max( 0.1, $total_weight_kg ), 2 );
        }

        $default_l = max( 1, (int) get_option( 'wcotl_default_package_length', 20 ) );
        $default_w_dim = max( 1, (int) get_option( 'wcotl_default_package_width', 20 ) );
        $default_h = max( 1, (int) get_option( 'wcotl_default_package_height', 10 ) );

        $parcels = [
            [
                'weight' => $total_weight_kg,
                'length' => $default_l,
                'width'  => $default_w_dim,
                'height' => $default_h,
            ],
        ];

        $packlink   = self::get_packlink_provider();
        $paccofacile = self::get_paccofacile_provider();

        return [
            'order_id'               => $order_id,
            'origin'                 => $origin,
            'destination'            => $destination,
            'parcels'                => $parcels,
            'packlink_configured'    => $packlink !== null && $packlink->is_configured(),
            'paccofacile_configured' => $paccofacile->is_configured(),
        ];
    }

    /**
     * Perform the actual rate comparison across Packlink and Paccofacile.
     */
    public static function compare_rates( array $origin, array $destination, array $parcels ): array {
        $all_rates         = [];
        $packlink_rates    = [];
        $paccofacile_rates = [];
        $errors            = [];

        $packlink    = self::get_packlink_provider();
        $paccofacile = self::get_paccofacile_provider();

        // 1. Packlink PRO
        if ( $packlink && $packlink->is_configured() ) {
            $res = $packlink->get_rates( $origin, $destination, $parcels );
            if ( $res['success'] && ! empty( $res['rates'] ) ) {
                $packlink_rates = $res['rates'];
                $all_rates      = array_merge( $all_rates, $packlink_rates );
            } elseif ( ! empty( $res['error'] ) ) {
                $errors['packlink'] = $res['error'];
            }
        } else {
            $errors['packlink'] = __( 'Packlink PRO is not configured or plugin is inactive.', 'wc-order-timeline' );
        }

        // 2. Paccofacile.it
        if ( $paccofacile->is_configured() ) {
            $res = $paccofacile->get_rates( $origin, $destination, $parcels );
            if ( $res['success'] && ! empty( $res['rates'] ) ) {
                $paccofacile_rates = $res['rates'];
                $all_rates         = array_merge( $all_rates, $paccofacile_rates );
            } elseif ( ! empty( $res['error'] ) ) {
                $errors['paccofacile'] = $res['error'];
            }
        } else {
            $errors['paccofacile'] = __( 'Paccofacile credentials (Account Number, API Key, Token) are not configured.', 'wc-order-timeline' );
        }

        // Sort all rates by price ascending
        usort( $all_rates, function( $a, $b ) {
            return ( $a['price'] <=> $b['price'] );
        } );

        // Sort provider specific rates
        usort( $packlink_rates, function( $a, $b ) {
            return ( $a['price'] <=> $b['price'] );
        } );
        usort( $paccofacile_rates, function( $a, $b ) {
            return ( $a['price'] <=> $b['price'] );
        } );

        $cheapest_overall     = $all_rates[0] ?? null;
        $cheapest_packlink    = $packlink_rates[0] ?? null;
        $cheapest_paccofacile = $paccofacile_rates[0] ?? null;

        $comparison = [
            'winner'            => null,
            'cheapest_price'    => $cheapest_overall['price'] ?? 0.0,
            'savings'           => 0.0,
            'summary_message'   => '',
        ];

        if ( $cheapest_packlink && $cheapest_paccofacile ) {
            $diff = abs( $cheapest_packlink['price'] - $cheapest_paccofacile['price'] );
            $comparison['savings'] = round( $diff, 2 );

            if ( $cheapest_paccofacile['price'] < $cheapest_packlink['price'] ) {
                $comparison['winner']          = 'paccofacile';
                $comparison['summary_message'] = sprintf(
                    /* translators: 1: Carrier name, 2: price, 3: saved amount */
                    __( 'Paccofacile is cheaper! %1$s is %2$s — saving %3$s compared to Packlink.', 'wc-order-timeline' ),
                    $cheapest_paccofacile['carrier'] . ' (' . $cheapest_paccofacile['service_name'] . ')',
                    wc_price( $cheapest_paccofacile['price'] ),
                    wc_price( $diff )
                );
            } elseif ( $cheapest_packlink['price'] < $cheapest_paccofacile['price'] ) {
                $comparison['winner']          = 'packlink';
                $comparison['summary_message'] = sprintf(
                    /* translators: 1: Carrier name, 2: price, 3: saved amount */
                    __( 'Packlink is cheaper! %1$s is %2$s — saving %3$s compared to Paccofacile.', 'wc-order-timeline' ),
                    $cheapest_packlink['carrier'] . ' (' . $cheapest_packlink['service_name'] . ')',
                    wc_price( $cheapest_packlink['price'] ),
                    wc_price( $diff )
                );
            } else {
                $comparison['winner']          = 'tie';
                $comparison['summary_message'] = __( 'Both providers offer the same lowest price!', 'wc-order-timeline' );
            }
        } elseif ( $cheapest_overall ) {
            $comparison['winner']          = $cheapest_overall['provider'];
            $comparison['summary_message'] = sprintf(
                /* translators: 1: provider name, 2: price */
                __( 'Best available option: %1$s at %2$s.', 'wc-order-timeline' ),
                $cheapest_overall['provider_name'] . ' - ' . $cheapest_overall['carrier'],
                wc_price( $cheapest_overall['price'] )
            );
        }

        return [
            'success'              => ! empty( $all_rates ),
            'all_rates'            => $all_rates,
            'packlink_rates'       => $packlink_rates,
            'paccofacile_rates'    => $paccofacile_rates,
            'cheapest_overall'     => $cheapest_overall,
            'cheapest_packlink'    => $cheapest_packlink,
            'cheapest_paccofacile' => $cheapest_paccofacile,
            'comparison'           => $comparison,
            'errors'               => $errors,
        ];
    }

    /**
     * AJAX handler: fetch pre-filled order shipping details.
     */
    public static function ajax_get_order_shipping_details(): void {
        check_ajax_referer( 'wcotl_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Not authorized.' );
        }

        $order_id = absint( $_POST['order_id'] ?? 0 );
        if ( ! $order_id ) {
            wp_send_json_error( 'Invalid order ID.' );
        }

        $data = self::get_order_shipping_details( $order_id );
        if ( ! $data ) {
            wp_send_json_error( 'Order not found.' );
        }

        wp_send_json_success( $data );
    }

    /**
     * AJAX handler: run comparison for submitted origin, destination, parcels.
     */
    public static function ajax_compare_shipping_rates(): void {
        check_ajax_referer( 'wcotl_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Not authorized.' );
        }

        $origin = [
            'country'  => sanitize_text_field( wp_unslash( $_POST['origin_country'] ?? 'IT' ) ),
            'postcode' => sanitize_text_field( wp_unslash( $_POST['origin_postcode'] ?? '' ) ),
            'city'     => sanitize_text_field( wp_unslash( $_POST['origin_city'] ?? '' ) ),
            'state'    => sanitize_text_field( wp_unslash( $_POST['origin_state'] ?? '' ) ),
        ];

        $destination = [
            'country'  => sanitize_text_field( wp_unslash( $_POST['dest_country'] ?? 'IT' ) ),
            'postcode' => sanitize_text_field( wp_unslash( $_POST['dest_postcode'] ?? '' ) ),
            'city'     => sanitize_text_field( wp_unslash( $_POST['dest_city'] ?? '' ) ),
            'state'    => sanitize_text_field( wp_unslash( $_POST['dest_state'] ?? '' ) ),
        ];

        $raw_parcels = $_POST['parcels'] ?? [];
        $parcels     = [];
        if ( is_array( $raw_parcels ) ) {
            foreach ( $raw_parcels as $p ) {
                $parcels[] = [
                    'weight' => max( 0.1, (float) ( $p['weight'] ?? 1.0 ) ),
                    'length' => max( 1, (int) ( $p['length'] ?? 20 ) ),
                    'width'  => max( 1, (int) ( $p['width'] ?? 20 ) ),
                    'height' => max( 1, (int) ( $p['height'] ?? 10 ) ),
                ];
            }
        }

        if ( empty( $parcels ) ) {
            $parcels[] = [
                'weight' => 1.0,
                'length' => 20,
                'width'  => 20,
                'height' => 10,
            ];
        }

        $result = self::compare_rates( $origin, $destination, $parcels );

        wp_send_json_success( $result );
    }

    /**
     * Render the comparison modal in admin footer on WooCommerce order screens.
     */
    public static function render_comparison_modal(): void {
        $screen = get_current_screen();
        if ( ! $screen ) {
            return;
        }

        $order_screens = [ 'shop_order', 'woocommerce_page_wc-orders' ];
        if ( ! in_array( $screen->id, $order_screens, true ) ) {
            return;
        }

        $packlink_configured    = (bool) get_option( 'wcotl_packlink_api_key', '' );
        $paccofacile_configured = self::get_paccofacile_provider()->is_configured();
        ?>
        <div id="wcotl-rates-modal-backdrop" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:100050;backdrop-filter:blur(2px);"></div>
        <div id="wcotl-rates-modal" style="display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);width:92%;max-width:960px;max-height:90vh;background:#fff;border-radius:10px;box-shadow:0 20px 40px rgba(0,0,0,0.3);z-index:100060;display:none;flex-direction:column;overflow:hidden;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Oxygen,Ubuntu,Cantarell,sans-serif;">
            
            <!-- Modal Header -->
            <div style="background:#1d2327;color:#fff;padding:16px 20px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #3c434a;">
                <div style="display:flex;align-items:center;gap:10px;">
                    <span class="dashicons dashicons-money-alt" style="font-size:24px;width:24px;height:24px;color:#72aee6;"></span>
                    <div>
                        <h2 style="margin:0;font-size:16px;font-weight:700;color:#fff;line-height:1.2;">
                            <?php esc_html_e( 'Compare Shipping Rates: Packlink PRO vs Paccofacile.it', 'wc-order-timeline' ); ?>
                        </h2>
                        <span id="wcotl-modal-order-subtitle" style="font-size:12px;color:#a7aaad;"></span>
                    </div>
                </div>
                <button type="button" id="wcotl-rates-modal-close" style="background:transparent;border:0;color:#c3c4c7;font-size:24px;line-height:1;cursor:pointer;padding:4px 8px;border-radius:4px;" title="<?php esc_attr_e( 'Close', 'wc-order-timeline' ); ?>">&times;</button>
            </div>

            <!-- Modal Content (Scrollable) -->
            <div style="padding:20px;overflow-y:auto;flex:1;background:#f0f0f1;">

                <!-- Setup Warning Notice if missing credentials -->
                <div id="wcotl-rates-config-warning" style="display:none;margin-bottom:15px;background:#fff8e5;border-left:4px solid #dba617;padding:10px 14px;border-radius:4px;font-size:13px;color:#614700;">
                    <div style="display:flex;align-items:center;justify-content:space-between;">
                        <div>
                            <strong><?php esc_html_e( 'Notice:', 'wc-order-timeline' ); ?></strong>
                            <span id="wcotl-rates-config-warning-text"></span>
                        </div>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=wcotl-settings' ) ); ?>" class="button button-small" target="_blank" style="margin-left:12px;">
                            <?php esc_html_e( 'Configure Providers', 'wc-order-timeline' ); ?> &rarr;
                        </a>
                    </div>
                </div>

                <!-- Parameters Grid -->
                <div style="background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:16px;margin-bottom:18px;box-shadow:0 1px 3px rgba(0,0,0,0.04);">
                    <div style="font-weight:700;font-size:13px;color:#1d2327;margin-bottom:12px;display:flex;align-items:center;justify-content:space-between;">
                        <span>📦 <?php esc_html_e( 'Shipment Parameters', 'wc-order-timeline' ); ?></span>
                        <button type="button" id="wcotl-refresh-btn" class="button button-small" style="display:flex;align-items:center;gap:4px;">
                            <span class="dashicons dashicons-update" style="font-size:14px;width:14px;height:14px;line-height:14px;"></span>
                            <?php esc_html_e( 'Get Quotes', 'wc-order-timeline' ); ?>
                        </button>
                    </div>

                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
                        <!-- Origin Card -->
                        <div style="background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px;padding:12px;">
                            <div style="font-weight:600;font-size:12px;color:#2c3338;margin-bottom:8px;display:flex;align-items:center;gap:5px;">
                                <span class="dashicons dashicons-store" style="font-size:15px;width:15px;height:15px;"></span>
                                <?php esc_html_e( 'Store Origin (Pickup)', 'wc-order-timeline' ); ?>
                            </div>
                            <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:8px;">
                                <div>
                                    <label style="font-size:11px;color:#646970;display:block;margin-bottom:2px;"><?php esc_html_e( 'City', 'wc-order-timeline' ); ?></label>
                                    <input type="text" id="wcotl-rate-orig-city" style="width:100%;font-size:12px;padding:3px 6px;">
                                </div>
                                <div>
                                    <label style="font-size:11px;color:#646970;display:block;margin-bottom:2px;"><?php esc_html_e( 'Postcode', 'wc-order-timeline' ); ?></label>
                                    <input type="text" id="wcotl-rate-orig-postcode" style="width:100%;font-size:12px;padding:3px 6px;">
                                </div>
                                <div>
                                    <label style="font-size:11px;color:#646970;display:block;margin-bottom:2px;"><?php esc_html_e( 'Country', 'wc-order-timeline' ); ?></label>
                                    <input type="text" id="wcotl-rate-orig-country" style="width:100%;font-size:12px;padding:3px 6px;" maxlength="2">
                                </div>
                            </div>
                            <input type="hidden" id="wcotl-rate-orig-state" value="">
                        </div>

                        <!-- Destination Card -->
                        <div style="background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px;padding:12px;">
                            <div style="font-weight:600;font-size:12px;color:#2c3338;margin-bottom:8px;display:flex;align-items:center;gap:5px;">
                                <span class="dashicons dashicons-location" style="font-size:15px;width:15px;height:15px;"></span>
                                <?php esc_html_e( 'Recipient Destination', 'wc-order-timeline' ); ?>
                            </div>
                            <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:8px;">
                                <div>
                                    <label style="font-size:11px;color:#646970;display:block;margin-bottom:2px;"><?php esc_html_e( 'City', 'wc-order-timeline' ); ?></label>
                                    <input type="text" id="wcotl-rate-dest-city" style="width:100%;font-size:12px;padding:3px 6px;">
                                </div>
                                <div>
                                    <label style="font-size:11px;color:#646970;display:block;margin-bottom:2px;"><?php esc_html_e( 'Postcode', 'wc-order-timeline' ); ?></label>
                                    <input type="text" id="wcotl-rate-dest-postcode" style="width:100%;font-size:12px;padding:3px 6px;">
                                </div>
                                <div>
                                    <label style="font-size:11px;color:#646970;display:block;margin-bottom:2px;"><?php esc_html_e( 'Country', 'wc-order-timeline' ); ?></label>
                                    <input type="text" id="wcotl-rate-dest-country" style="width:100%;font-size:12px;padding:3px 6px;" maxlength="2">
                                </div>
                            </div>
                            <input type="hidden" id="wcotl-rate-dest-state" value="">
                        </div>
                    </div>

                    <!-- Parcels List -->
                    <div>
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
                            <span style="font-size:12px;font-weight:600;color:#50575e;"><?php esc_html_e( 'Parcels (Weight & Dimensions in cm)', 'wc-order-timeline' ); ?>:</span>
                            <button type="button" id="wcotl-add-parcel-btn" class="button button-small" style="font-size:11px;">+ <?php esc_html_e( 'Add Package', 'wc-order-timeline' ); ?></button>
                        </div>
                        <div id="wcotl-parcels-container" style="display:flex;flex-direction:column;gap:6px;">
                            <!-- Populated dynamically -->
                        </div>
                    </div>
                </div>

                <!-- Comparison Winner Banner -->
                <div id="wcotl-winner-banner" style="display:none;margin-bottom:18px;background:#edfaef;border:1px solid #68de7c;border-left:5px solid #008a20;padding:12px 16px;border-radius:6px;">
                    <div style="display:flex;align-items:center;justify-content:space-between;">
                        <div style="display:flex;align-items:center;gap:10px;">
                            <span class="dashicons dashicons-yes-alt" style="font-size:24px;width:24px;height:24px;color:#008a20;"></span>
                            <div>
                                <div id="wcotl-winner-title" style="font-weight:700;font-size:14px;color:#0d5926;"></div>
                                <div id="wcotl-winner-details" style="font-size:12px;color:#2c3338;margin-top:2px;"></div>
                            </div>
                        </div>
                        <div id="wcotl-winner-savings" style="text-align:right;"></div>
                    </div>
                </div>

                <!-- Rate Results Section -->
                <div id="wcotl-rates-results-card" style="background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:16px;box-shadow:0 1px 3px rgba(0,0,0,0.04);">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;border-bottom:1px solid #f0f0f1;padding-bottom:10px;">
                        <!-- Filter Tabs -->
                        <div style="display:flex;gap:6px;">
                            <button type="button" class="wcotl-tab-btn button button-primary button-small" data-tab="all"><?php esc_html_e( 'All Options', 'wc-order-timeline' ); ?> <span id="wcotl-count-all" class="count">(0)</span></button>
                            <button type="button" class="wcotl-tab-btn button button-secondary button-small" data-tab="packlink"><?php esc_html_e( 'Packlink PRO', 'wc-order-timeline' ); ?> <span id="wcotl-count-packlink" class="count">(0)</span></button>
                            <button type="button" class="wcotl-tab-btn button button-secondary button-small" data-tab="paccofacile"><?php esc_html_e( 'Paccofacile.it', 'wc-order-timeline' ); ?> <span id="wcotl-count-paccofacile" class="count">(0)</span></button>
                        </div>
                        <div style="font-size:11px;color:#646970;">
                            <?php esc_html_e( 'Sorted by lowest price', 'wc-order-timeline' ); ?>
                        </div>
                    </div>

                    <!-- Loading Spinner -->
                    <div id="wcotl-rates-loading" style="display:none;text-align:center;padding:40px 20px;">
                        <span class="spinner is-active" style="float:none;margin:0 auto 10px auto;"></span>
                        <div style="font-size:13px;color:#50575e;"><?php esc_html_e( 'Contacting Packlink PRO and Paccofacile.it APIs for live quotes...', 'wc-order-timeline' ); ?></div>
                    </div>

                    <!-- Rates Table -->
                    <div id="wcotl-rates-table-wrap" style="overflow-x:auto;">
                        <table class="wp-list-table widefat striped" style="border:0;">
                            <thead>
                                <tr>
                                    <th style="width:130px;"><?php esc_html_e( 'Provider', 'wc-order-timeline' ); ?></th>
                                    <th><?php esc_html_e( 'Carrier / Courier', 'wc-order-timeline' ); ?></th>
                                    <th><?php esc_html_e( 'Service & Delivery', 'wc-order-timeline' ); ?></th>
                                    <th style="width:130px;text-align:right;"><?php esc_html_e( 'Total Price', 'wc-order-timeline' ); ?></th>
                                    <th style="width:120px;text-align:center;"><?php esc_html_e( 'Action', 'wc-order-timeline' ); ?></th>
                                </tr>
                            </thead>
                            <tbody id="wcotl-rates-tbody">
                                <tr>
                                    <td colspan="5" style="text-align:center;color:#646970;padding:24px;">
                                        <?php esc_html_e( 'Click "Get Quotes" to compare shipping rates.', 'wc-order-timeline' ); ?>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>

            <!-- Modal Footer -->
            <div style="background:#f6f7f7;border-top:1px solid #dcdcde;padding:12px 20px;display:flex;align-items:center;justify-content:space-between;">
                <div style="font-size:11px;color:#646970;">
                    <?php esc_html_e( 'Prices include taxes & surcharges where returned by the courier service.', 'wc-order-timeline' ); ?>
                </div>
                <div style="display:flex;gap:10px;">
                    <a id="wcotl-link-packlink" href="https://pro.packlink.it/private/shipments/ready-to-purchase" target="_blank" class="button" style="display:flex;align-items:center;gap:4px;">
                        <span>Packlink Dashboard</span> <span class="dashicons dashicons-external" style="font-size:14px;width:14px;height:14px;line-height:14px;"></span>
                    </a>
                    <a id="wcotl-link-paccofacile" href="https://www.paccofacile.it/preventivo/" target="_blank" class="button" style="display:flex;align-items:center;gap:4px;">
                        <span>Paccofacile.it</span> <span class="dashicons dashicons-external" style="font-size:14px;width:14px;height:14px;line-height:14px;"></span>
                    </a>
                    <button type="button" class="button button-primary" id="wcotl-rates-modal-done"><?php esc_html_e( 'Done', 'wc-order-timeline' ); ?></button>
                </div>
            </div>

        </div>

        <script>
        (function($) {
            let currentOrderId = 0;
            let currentRates = [];
            let activeTab = 'all';

            // Open Modal
            $(document).on('click', '.wcotl-open-rate-comparator', function(e) {
                e.preventDefault();
                currentOrderId = $(this).data('order-id') || 0;
                if (!currentOrderId) return;

                $('#wcotl-modal-order-subtitle').text('Order #' + currentOrderId);
                $('#wcotl-rates-modal-backdrop, #wcotl-rates-modal').show();
                $('#wcotl-winner-banner').hide();

                loadOrderDetails(currentOrderId);
            });

            // Close Modal
            $(document).on('click', '#wcotl-rates-modal-close, #wcotl-rates-modal-backdrop, #wcotl-rates-modal-done', function() {
                $('#wcotl-rates-modal-backdrop, #wcotl-rates-modal').hide();
            });

            // Add Parcel Row
            $('#wcotl-add-parcel-btn').on('click', function() {
                addParcelRow(1.0, 20, 20, 10);
            });

            // Remove Parcel Row
            $(document).on('click', '.wcotl-remove-parcel-btn', function() {
                if ($('.wcotl-parcel-row').length > 1) {
                    $(this).closest('.wcotl-parcel-row').remove();
                } else {
                    alert('At least one parcel is required.');
                }
            });

            function addParcelRow(weight, l, w, h) {
                var idx = $('.wcotl-parcel-row').length + 1;
                var html = `
                    <div class="wcotl-parcel-row" style="display:grid;grid-template-columns:40px 1fr 1fr 1fr 1fr 30px;gap:8px;align-items:center;background:#fff;border:1px solid #dcdcde;padding:6px 10px;border-radius:4px;">
                        <span style="font-weight:600;font-size:11px;color:#50575e;">#${idx}</span>
                        <div>
                            <input type="number" step="0.01" min="0.01" class="wcotl-parcel-weight" value="${weight}" style="width:100%;font-size:11px;padding:3px 5px;" placeholder="Weight (kg)">
                        </div>
                        <div>
                            <input type="number" step="1" min="1" class="wcotl-parcel-length" value="${l}" style="width:100%;font-size:11px;padding:3px 5px;" placeholder="Length (cm)">
                        </div>
                        <div>
                            <input type="number" step="1" min="1" class="wcotl-parcel-width" value="${w}" style="width:100%;font-size:11px;padding:3px 5px;" placeholder="Width (cm)">
                        </div>
                        <div>
                            <input type="number" step="1" min="1" class="wcotl-parcel-height" value="${h}" style="width:100%;font-size:11px;padding:3px 5px;" placeholder="Height (cm)">
                        </div>
                        <button type="button" class="wcotl-remove-parcel-btn" style="background:none;border:none;color:#d63638;cursor:pointer;font-size:16px;line-height:1;" title="Remove">&times;</button>
                    </div>
                `;
                $('#wcotl-parcels-container').append(html);
            }

            function loadOrderDetails(orderId) {
                $('#wcotl-rates-tbody').html('<tr><td colspan="5" style="text-align:center;padding:20px;"><span class="spinner is-active" style="float:none;"></span> Loading order details...</td></tr>');
                $('#wcotl-parcels-container').empty();

                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: {
                        action: 'wcotl_get_order_shipping_details',
                        nonce: <?php echo wp_json_encode( wp_create_nonce( 'wcotl_admin_nonce' ) ); ?>,
                        order_id: orderId
                    },
                    success: function(res) {
                        if (res.success && res.data) {
                            var d = res.data;
                            $('#wcotl-rate-orig-city').val(d.origin.city || '');
                            $('#wcotl-rate-orig-postcode').val(d.origin.postcode || '');
                            $('#wcotl-rate-orig-country').val(d.origin.country || 'IT');
                            $('#wcotl-rate-orig-state').val(d.origin.state || '');

                            $('#wcotl-rate-dest-city').val(d.destination.city || '');
                            $('#wcotl-rate-dest-postcode').val(d.destination.postcode || '');
                            $('#wcotl-rate-dest-country').val(d.destination.country || 'IT');
                            $('#wcotl-rate-dest-state').val(d.destination.state || '');

                            if (d.parcels && d.parcels.length) {
                                d.parcels.forEach(function(p) {
                                    addParcelRow(p.weight, p.length, p.width, p.height);
                                });
                            } else {
                                addParcelRow(1.0, 20, 20, 10);
                            }

                            // Config check notice
                            var warnings = [];
                            if (!d.packlink_configured) {
                                warnings.push('Packlink PRO API key is not configured.');
                            }
                            if (!d.paccofacile_configured) {
                                warnings.push('Paccofacile.it API credentials are not configured.');
                            }
                            if (warnings.length > 0) {
                                $('#wcotl-rates-config-warning-text').text(warnings.join(' '));
                                $('#wcotl-rates-config-warning').show();
                            } else {
                                $('#wcotl-rates-config-warning').hide();
                            }

                            // Auto trigger compare
                            fetchQuotes();
                        } else {
                            $('#wcotl-rates-tbody').html('<tr><td colspan="5" style="text-align:center;color:#d63638;padding:20px;">' + (res.data || 'Failed to load order.') + '</td></tr>');
                        }
                    },
                    error: function() {
                        $('#wcotl-rates-tbody').html('<tr><td colspan="5" style="text-align:center;color:#d63638;padding:20px;">Network error loading order details.</td></tr>');
                    }
                });
            }

            $('#wcotl-refresh-btn').on('click', function(e) {
                e.preventDefault();
                fetchQuotes();
            });

            function fetchQuotes() {
                var parcels = [];
                $('.wcotl-parcel-row').each(function() {
                    parcels.push({
                        weight: parseFloat($(this).find('.wcotl-parcel-weight').val()) || 1.0,
                        length: parseInt($(this).find('.wcotl-parcel-length').val(), 10) || 20,
                        width:  parseInt($(this).find('.wcotl-parcel-width').val(), 10) || 20,
                        height: parseInt($(this).find('.wcotl-parcel-height').val(), 10) || 10
                    });
                });

                $('#wcotl-rates-loading').show();
                $('#wcotl-rates-table-wrap').hide();
                $('#wcotl-winner-banner').hide();

                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: {
                        action: 'wcotl_compare_shipping_rates',
                        nonce: <?php echo wp_json_encode( wp_create_nonce( 'wcotl_admin_nonce' ) ); ?>,
                        origin_city: $('#wcotl-rate-orig-city').val(),
                        origin_postcode: $('#wcotl-rate-orig-postcode').val(),
                        origin_country: $('#wcotl-rate-orig-country').val(),
                        origin_state: $('#wcotl-rate-orig-state').val(),
                        dest_city: $('#wcotl-rate-dest-city').val(),
                        dest_postcode: $('#wcotl-rate-dest-postcode').val(),
                        dest_country: $('#wcotl-rate-dest-country').val(),
                        dest_state: $('#wcotl-rate-dest-state').val(),
                        parcels: parcels
                    },
                    success: function(res) {
                        $('#wcotl-rates-loading').hide();
                        $('#wcotl-rates-table-wrap').show();

                        if (res.success && res.data) {
                            renderQuotes(res.data);
                        } else {
                            var errMsg = (res.data && res.data.errors) ? Object.values(res.data.errors).join('<br>') : 'No rates found.';
                            $('#wcotl-rates-tbody').html('<tr><td colspan="5" style="text-align:center;color:#d63638;padding:24px;">' + errMsg + '</td></tr>');
                        }
                    },
                    error: function() {
                        $('#wcotl-rates-loading').hide();
                        $('#wcotl-rates-table-wrap').show();
                        $('#wcotl-rates-tbody').html('<tr><td colspan="5" style="text-align:center;color:#d63638;padding:24px;">Error connecting to quoting servers.</td></tr>');
                    }
                });
            }

            function renderQuotes(data) {
                currentRates = data.all_rates || [];

                // Winner banner
                if (data.comparison && data.comparison.summary_message && data.cheapest_overall) {
                    var win = data.comparison;
                    $('#wcotl-winner-title').text(win.summary_message);
                    
                    var details = '';
                    if (data.cheapest_packlink && data.cheapest_paccofacile) {
                        details = 'Packlink cheapest: <strong>€' + parseFloat(data.cheapest_packlink.price).toFixed(2) + '</strong> (' + data.cheapest_packlink.carrier + ') vs Paccofacile cheapest: <strong>€' + parseFloat(data.cheapest_paccofacile.price).toFixed(2) + '</strong> (' + data.cheapest_paccofacile.carrier + ')';
                    }
                    $('#wcotl-winner-details').html(details);

                    if (win.savings > 0) {
                        $('#wcotl-winner-savings').html('<span style="font-size:18px;font-weight:700;color:#008a20;display:block;">Save €' + win.savings.toFixed(2) + '</span><span style="font-size:11px;color:#50575e;">on this shipment</span>');
                    } else {
                        $('#wcotl-winner-savings').empty();
                    }

                    $('#wcotl-winner-banner').show();
                } else {
                    $('#wcotl-winner-banner').hide();
                }

                // Update tab counters
                var packlinkCount = (data.packlink_rates || []).length;
                var paccoCount    = (data.paccofacile_rates || []).length;
                $('#wcotl-count-all').text('(' + currentRates.length + ')');
                $('#wcotl-count-packlink').text('(' + packlinkCount + ')');
                $('#wcotl-count-paccofacile').text('(' + paccoCount + ')');

                renderTable();
            }

            // Tab filtering
            $(document).on('click', '.wcotl-tab-btn', function() {
                $('.wcotl-tab-btn').removeClass('button-primary').addClass('button-secondary');
                $(this).removeClass('button-secondary').addClass('button-primary');
                activeTab = $(this).data('tab');
                renderTable();
            });

            function renderTable() {
                var tbody = $('#wcotl-rates-tbody');
                tbody.empty();

                var filtered = currentRates.filter(function(r) {
                    if (activeTab === 'all') return true;
                    return r.provider === activeTab;
                });

                if (filtered.length === 0) {
                    tbody.html('<tr><td colspan="5" style="text-align:center;color:#646970;padding:24px;">No shipping options found for this provider.</td></tr>');
                    return;
                }

                var lowestPrice = currentRates.length > 0 ? parseFloat(currentRates[0].price) : null;

                filtered.forEach(function(r, idx) {
                    var isCheapest = (parseFloat(r.price) === lowestPrice);
                    var providerBadge = (r.provider === 'packlink')
                        ? '<span style="background:#0063d1;color:#fff;font-weight:600;font-size:11px;padding:2px 7px;border-radius:10px;display:inline-block;">Packlink PRO</span>'
                        : '<span style="background:#e85b13;color:#fff;font-weight:600;font-size:11px;padding:2px 7px;border-radius:10px;display:inline-block;">Paccofacile.it</span>';

                    var priceHtml = '<strong style="font-size:14px;color:#1d2327;">€' + parseFloat(r.price).toFixed(2) + '</strong>';
                    if (isCheapest) {
                        priceHtml += ' <span style="background:#008a20;color:#fff;font-size:10px;font-weight:700;padding:2px 6px;border-radius:3px;margin-left:4px;text-transform:uppercase;">Cheapest</span>';
                    }

                    var transit = r.transit_time ? '<span style="color:#50575e;font-size:11px;">⏱ ' + escapeHtml(r.transit_time) + '</span>' : '';

                    var rowStyle = isCheapest ? 'background:#f0fbf0;' : '';

                    var actionLink = (r.provider === 'packlink')
                        ? 'https://pro.packlink.it/private/shipments/ready-to-purchase'
                        : 'https://www.paccofacile.it/preventivo/';

                    var tr = `
                        <tr style="${rowStyle}">
                            <td style="vertical-align:middle;">${providerBadge}</td>
                            <td style="vertical-align:middle;font-weight:600;color:#1d2327;">
                                ${escapeHtml(r.carrier)}
                            </td>
                            <td style="vertical-align:middle;">
                                <div style="font-weight:500;color:#2c3338;">${escapeHtml(r.service_name || r.carrier)}</div>
                                ${transit}
                            </td>
                            <td style="vertical-align:middle;text-align:right;">
                                ${priceHtml}
                            </td>
                            <td style="vertical-align:middle;text-align:center;">
                                <a href="${actionLink}" target="_blank" class="button button-small" style="font-size:11px;">
                                    Book &rarr;
                                </a>
                            </td>
                        </tr>
                    `;
                    tbody.append(tr);
                });
            }

            function escapeHtml(str) {
                if (!str) return '';
                return String(str)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;');
            }

        })(jQuery);
        </script>
        <?php
    }
}
