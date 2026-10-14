<?php
/**
 * Packlink PRO API Provider
 *
 * Implements WCOTL_Tracking_Provider using the Packlink PRO REST API.
 * All HTTP calls go through WordPress's wp_remote_get() so the WP
 * HTTP API (proxy settings, SSL config, etc.) is respected.
 *
 * Key differences from the 17TRACK provider:
 *   - Tracking is done by Packlink's own "Shipment Reference" (e.g. ESXXXXXXXXXXXX),
 *     NOT by a carrier-specific tracking number. There is therefore no concept of a
 *     "carrier code" and no need to register the shipment first.
 *   - Availability is gated: this provider is only usable when the
 *     "Packlink PRO Shipping for WooCommerce" plugin is active (class check).
 *
 * Relevant endpoint:
 *   GET https://api.packlink.com/v1/shipments/{reference}/tracking-history
 *
 * Packlink shipment statuses mapped to normalised statuses:
 *   pending          => in_transit   (draft created, not yet picked up)
 *   processing       => in_transit   (carrier accepted)
 *   readyForShipping => in_transit   (label ready)
 *   inTransit        => in_transit
 *   delivered        => delivered
 *   cancelled        => expired
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WCOTL_Packlink_Provider extends WCOTL_Tracking_Provider {

    /** API base URL */
    const BASE_URL = 'https://api.packlink.com/v1/';

    /** Shipments endpoint */
    const SHIPMENTS_ENDPOINT = 'shipments/';

    /**
     * Packlink shipment status string => normalised status mapping.
     */
    const STATUS_MAP = [
        'pending'              => 'in_transit',
        'processing'           => 'in_transit',
        'ready_for_shipping'   => 'in_transit',
        'readyforshipping'     => 'in_transit',
        'in_transit'           => 'in_transit',
        'intransit'            => 'in_transit',
        'out_for_delivery'     => 'in_transit',
        'outfordelivery'       => 'in_transit',
        'delivered'            => 'delivered',
        'completed'            => 'delivered',
        'delivered_to_recipient' => 'delivered',
        'cancelled'            => 'expired',
        'canceled'             => 'expired',
        'expired'              => 'expired',
    ];

    /** @var string */
    private string $api_key;

    public function __construct( string $api_key ) {
        $this->api_key = trim( $api_key );
    }

    /* ------------------------------------------------------------------
     * WCOTL_Tracking_Provider implementation
     * ------------------------------------------------------------------ */

    public function get_name(): string {
        return 'Packlink PRO';
    }

    /**
     * The provider is configured when an API key is set AND the Packlink PRO
     * Shipping plugin is active (its bootstrap class is loaded).
     */
    public function is_configured(): bool {
        return $this->api_key !== '' && self::is_packlink_plugin_active();
    }

    /**
     * Packlink does not require pre-registration of a shipment reference.
     * This is a no-op that always returns success.
     *
     * @param string          $real_tracking_number  Packlink shipment reference.
     * @param int|string|null $carrier_code          Unused (Packlink has no carrier codes).
     */
    public function register_shipment( string $real_tracking_number, int|string|null $carrier_code = null ): array {
        // No registration step needed with Packlink PRO.
        return [ 'success' => true, 'carrier_code' => null, 'error' => null ];
    }

    /**
     * Fetch raw tracking history/events from Packlink API endpoints.
     *
     * @param string $clean_ref
     * @return array
     */
    private function fetch_raw_events( string $clean_ref ): array {
        // 1. Try official endpoint: /shipments/{reference}/track
        $history_resp = $this->request( self::SHIPMENTS_ENDPOINT . rawurlencode( $clean_ref ) . '/track' );

        if ( is_wp_error( $history_resp ) ) {
            // 2. Fallback to legacy /tracking-history
            $history_resp = $this->request( self::SHIPMENTS_ENDPOINT . rawurlencode( $clean_ref ) . '/tracking-history' );
        }

        if ( ! is_wp_error( $history_resp ) ) {
            $history = $history_resp['body'] ?? [];
            if ( isset( $history['data'] ) && is_array( $history['data'] ) ) {
                return $history['data'];
            }
            if ( is_array( $history ) && ! empty( $history ) && isset( $history[0] ) ) {
                return $history;
            }
        }

        return [];
    }

    /**
     * Fetch the latest tracking events for a Packlink shipment reference.
     *
     * In the Packlink PRO API:
     *   - GET /v1/shipments/{reference}        returns shipment details (including 'status')
     *   - GET /v1/shipments/{reference}/track  returns tracking events: [ { "timestamp": ..., "description": ..., "city": ... }, ... ]
     *
     * If /track returns 404 (common when the shipment has just been created or no carrier events exist yet),
     * we treat it as an empty event list rather than a fatal error.
     *
     * @param string     $real_tracking_number  Packlink shipment reference.
     * @param int|string $carrier_code          Unused.
     */
    public function get_tracking_info( string $real_tracking_number, int|string $carrier_code = 0 ): array {
        $clean_ref = trim( $real_tracking_number );

        // 1. Fetch shipment status.
        $shipment_resp = $this->request( self::SHIPMENTS_ENDPOINT . rawurlencode( $clean_ref ) );
        if ( is_wp_error( $shipment_resp ) ) {
            $err_msg = $shipment_resp->get_error_message();

            if ( str_contains( $err_msg, 'HTTP 404' ) ) {
                return $this->error_response(
                    sprintf(
                        'Packlink shipment reference "%s" was not found (404). Please verify that the code matches the Packlink Shipment Reference in your Packlink PRO account (e.g. ES2024...) and that your Packlink API key matches this account.',
                        $clean_ref
                    )
                );
            }

            return $this->error_response( $err_msg );
        }

        $shipment_body   = $shipment_resp['body'] ?? [];
        $raw_status      = (string) ( $shipment_body['status'] ?? $shipment_body['state'] ?? '' );
        $normalized_key  = strtolower( trim( preg_replace( '/[_\-\s]+/', '', $raw_status ) ) );

        $status = self::STATUS_MAP[ $normalized_key ] ?? ( self::STATUS_MAP[ strtolower( trim( $raw_status ) ) ] ?? null );
        if ( ! $status ) {
            if ( str_contains( $normalized_key, 'delivered' ) || str_contains( $normalized_key, 'completed' ) || str_contains( $normalized_key, 'entregado' ) || str_contains( $normalized_key, 'livre' ) || str_contains( $normalized_key, 'consegnato' ) ) {
                $status = 'delivered';
            } elseif ( str_contains( $normalized_key, 'cancel' ) || str_contains( $normalized_key, 'expired' ) ) {
                $status = 'expired';
            } else {
                $status = 'in_transit';
            }
        }

        // 2. Fetch tracking events.
        $raw_events = $this->fetch_raw_events( $clean_ref );

        $events    = [];
        $seen_keys = [];

        foreach ( $raw_events as $ev ) {
            if ( ! is_array( $ev ) ) {
                continue;
            }

            // Packlink returns unix timestamps; fall back to created_at / date string.
            $ts = null;
            if ( ! empty( $ev['timestamp'] ) && is_numeric( $ev['timestamp'] ) ) {
                $ts = (int) $ev['timestamp'];
            } elseif ( ! empty( $ev['created_at'] ) ) {
                $ts = strtotime( $ev['created_at'] );
            } elseif ( ! empty( $ev['date'] ) ) {
                $ts = strtotime( $ev['date'] );
            }

            if ( ! $ts ) continue;

            $date  = $this->format_date( $ts );
            $label = sanitize_text_field( $ev['description'] ?? $ev['status'] ?? $ev['message'] ?? '' );
            if ( empty( $label ) ) continue;

            // Deduplicate.
            $key = $date . '|' . $label;
            if ( isset( $seen_keys[ $key ] ) ) continue;
            $seen_keys[ $key ] = true;

            $city = isset( $ev['city'] ) && $ev['city'] !== '' ? sanitize_text_field( $ev['city'] ) : null;

            $events[] = [
                'date'     => $date,
                'label'    => $label,
                'location' => $city,
            ];
        }

        usort( $events, fn( $a, $b ) => strcmp( $a['date'], $b['date'] ) );

        // Fallback: If top-level status is not yet 'delivered', check if any tracking event indicates delivery.
        if ( $status !== 'delivered' && ! empty( $events ) ) {
            foreach ( $events as $event_item ) {
                $ev_label = strtolower( $event_item['label'] ?? '' );
                if (
                    str_contains( $ev_label, 'delivered' ) ||
                    str_contains( $ev_label, 'livré' ) ||
                    str_contains( $ev_label, 'entregado' ) ||
                    str_contains( $ev_label, 'consegnato' ) ||
                    str_contains( $ev_label, 'zugestellt' ) ||
                    str_contains( $ev_label, 'bezorgd' )
                ) {
                    $status = 'delivered';
                    break;
                }
            }
        }

        return [
            'success' => true,
            'status'  => $status,
            'events'  => $events,
            'raw'     => $raw_events,
            'error'   => null,
        ];
    }

    /**
     * Carrier detection is not applicable to Packlink PRO.
     * Packlink tracks by its own shipment reference, not by carrier code.
     *
     * @param string $real_tracking_number
     * @return array<int, string>
     */
    public function detect_carriers( string $real_tracking_number ): array {
        return [];
    }

    /* ------------------------------------------------------------------
     * Static helpers
     * ------------------------------------------------------------------ */

    /**
     * Retrieve Packlink PRO shipping details for a given WooCommerce order.
     *
     * Detects:
     *   - carrier_name: Custom carrier name configured by the admin (e.g. "brt - 2 DAYS")
     *   - carrier_tracking_numbers: Array of tracking codes from the carrier (e.g. Bartolini code)
     *   - carrier_tracking_url: Tracking link on the carrier's site
     *   - reference: Packlink shipment reference (e.g. "ES...", "IT...") used for Packlink API calls
     *   - shipping_price: Packlink shipping price
     *   - currency: Currency code
     *   - status: Current Packlink shipping status
     *
     * Looks up data via:
     *   1. Official Packlink PRO service classes (OrderShipmentDetailsService & Shipping_Method_Helper)
     *   2. Custom Packlink entity database table ({$wpdb->prefix}packlink_entity)
     *   3. WooCommerce order meta and shipping item fallback
     *
     * @param int $order_id
     * @return array{
     *     carrier_name: string,
     *     carrier_tracking_numbers: array<string>,
     *     carrier_tracking_url: string,
     *     reference: string,
     *     shipping_price: float,
     *     currency: string,
     *     status: string,
     * }|null Returns null if no Packlink shipment is found for the order.
     */
    public static function get_order_packlink_data( int $order_id ): ?array {
        $order_id = absint( $order_id );
        if ( ! $order_id ) {
            return null;
        }

        $reference                = '';
        $carrier_tracking_numbers = [];
        $carrier_tracking_url     = '';
        $shipping_price           = 0.0;
        $currency                 = '';
        $status                   = '';
        $carrier_name             = '';

        $wc_order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;

        // 1. Attempt lookup via Packlink PRO service classes if loaded
        if ( class_exists( 'Logeecom\Infrastructure\ServiceRegister' ) && class_exists( 'Packlink\BusinessLogic\OrderShipmentDetails\OrderShipmentDetailsService' ) ) {
            try {
                $shipment_details_service = \Logeecom\Infrastructure\ServiceRegister::getService(
                    \Packlink\BusinessLogic\OrderShipmentDetails\OrderShipmentDetailsService::CLASS_NAME
                );
                if ( $shipment_details_service && method_exists( $shipment_details_service, 'getDetailsByOrderId' ) ) {
                    $order_details = $shipment_details_service->getDetailsByOrderId( (string) $order_id );
                    if ( $order_details ) {
                        if ( method_exists( $order_details, 'getReference' ) ) {
                            $reference = (string) $order_details->getReference();
                        }
                        if ( method_exists( $order_details, 'getCarrierTrackingNumbers' ) ) {
                            $raw_codes = (array) $order_details->getCarrierTrackingNumbers();
                            foreach ( $raw_codes as $rc ) {
                                if ( is_scalar( $rc ) && trim( (string) $rc ) !== '' ) {
                                    $carrier_tracking_numbers[] = trim( (string) $rc );
                                }
                            }
                        }
                        if ( method_exists( $order_details, 'getCarrierTrackingUrl' ) ) {
                            $carrier_tracking_url = (string) $order_details->getCarrierTrackingUrl();
                        }
                        if ( method_exists( $order_details, 'getShippingCost' ) ) {
                            $shipping_price = (float) $order_details->getShippingCost();
                        }
                        if ( method_exists( $order_details, 'getCurrency' ) ) {
                            $currency = (string) $order_details->getCurrency();
                        }
                        if ( method_exists( $order_details, 'getShippingStatus' ) ) {
                            $status = (string) $order_details->getShippingStatus();
                        }
                    }
                }
            } catch ( \Throwable $e ) {
                // Ignore and fall back to database
            }
        }

        // 2. Fallback to Packlink database table ({$wpdb->prefix}packlink_entity)
        if ( empty( $reference ) || empty( $carrier_tracking_numbers ) ) {
            global $wpdb;
            $table = $wpdb->prefix . 'packlink_entity';
            $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;

            if ( $table_exists ) {
                $row = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT data FROM {$table} WHERE type = 'OrderShipmentDetails' AND (index_1 = %s OR index_2 = %s) LIMIT 1",
                        (string) $order_id,
                        (string) $order_id
                    )
                );

                if ( ! $row ) {
                    $pattern1 = '%"orderId":"' . $wpdb->esc_like( (string) $order_id ) . '"%';
                    $pattern2 = '%"orderId":' . $wpdb->esc_like( (string) $order_id ) . ',%';
                    $pattern3 = '%"orderId":' . $wpdb->esc_like( (string) $order_id ) . '}%';
                    $pattern4 = '%"order_id":"' . $wpdb->esc_like( (string) $order_id ) . '"%';
                    $row = $wpdb->get_row(
                        $wpdb->prepare(
                            "SELECT data FROM {$table} WHERE type = 'OrderShipmentDetails' AND (data LIKE %s OR data LIKE %s OR data LIKE %s OR data LIKE %s) LIMIT 1",
                            $pattern1, $pattern2, $pattern3, $pattern4
                        )
                    );
                }

                if ( $row && ! empty( $row->data ) ) {
                    $data = json_decode( $row->data, true );
                    if ( is_array( $data ) ) {
                        if ( empty( $reference ) && ! empty( $data['reference'] ) ) {
                            $reference = (string) $data['reference'];
                        }
                        if ( empty( $carrier_tracking_numbers ) && ! empty( $data['carrierTrackingNumbers'] ) ) {
                            $raw_codes = (array) $data['carrierTrackingNumbers'];
                            foreach ( $raw_codes as $rc ) {
                                if ( is_scalar( $rc ) && trim( (string) $rc ) !== '' ) {
                                    $carrier_tracking_numbers[] = trim( (string) $rc );
                                }
                            }
                        }
                        if ( empty( $carrier_tracking_url ) && ! empty( $data['carrierTrackingUrl'] ) ) {
                            $carrier_tracking_url = (string) $data['carrierTrackingUrl'];
                        }
                        if ( empty( $shipping_price ) && isset( $data['shippingCost'] ) ) {
                            $shipping_price = (float) $data['shippingCost'];
                        }
                        if ( empty( $currency ) && ! empty( $data['currency'] ) ) {
                            $currency = (string) $data['currency'];
                        }
                        if ( empty( $status ) && ! empty( $data['shippingStatus'] ) ) {
                            $status = (string) $data['shippingStatus'];
                        }
                    }
                }
            }
        }

        // 3. Fallback to WooCommerce order meta
        if ( $wc_order ) {
            if ( empty( $reference ) ) {
                $ref_meta = $wc_order->get_meta( '_packlink_reference' )
                    ?: ( $wc_order->get_meta( '_packlink_shipment_reference' )
                    ?: ( $wc_order->get_meta( 'packlink_reference' )
                    ?: $wc_order->get_meta( '_packlink_reference_number' ) ) );
                if ( is_scalar( $ref_meta ) && trim( (string) $ref_meta ) !== '' ) {
                    $reference = trim( (string) $ref_meta );
                }
            }
            if ( empty( $carrier_tracking_numbers ) ) {
                $ctn_meta = $wc_order->get_meta( '_packlink_carrier_tracking_codes' )
                    ?: ( $wc_order->get_meta( '_carrier_tracking_codes' )
                    ?: ( $wc_order->get_meta( '_packlink_carrier_tracking_code' )
                    ?: $wc_order->get_meta( '_tracking_number' ) ) );
                if ( is_array( $ctn_meta ) ) {
                    foreach ( $ctn_meta as $c ) {
                        if ( is_scalar( $c ) && trim( (string) $c ) !== '' ) {
                            $carrier_tracking_numbers[] = trim( (string) $c );
                        }
                    }
                } elseif ( is_scalar( $ctn_meta ) && trim( (string) $ctn_meta ) !== '' ) {
                    $carrier_tracking_numbers[] = trim( (string) $ctn_meta );
                }
            }
        }

        // 4. Resolve carrier name (admin custom name, e.g. "brt - 2 DAYS")
        if ( $wc_order ) {
            if ( class_exists( 'Packlink\WooCommerce\Components\ShippingMethod\Shipping_Method_Helper' ) ) {
                try {
                    $shipping_method = \Packlink\WooCommerce\Components\ShippingMethod\Shipping_Method_Helper::get_packlink_shipping_method_from_order( $wc_order );
                    if ( $shipping_method && method_exists( $shipping_method, 'getTitle' ) ) {
                        $carrier_name = (string) $shipping_method->getTitle();
                    }
                } catch ( \Throwable $e ) {
                    // Ignore
                }
            }

            if ( empty( $carrier_name ) && method_exists( $wc_order, 'get_shipping_methods' ) ) {
                $shipping_methods = $wc_order->get_shipping_methods();
                if ( ! empty( $shipping_methods ) ) {
                    $first_shipping = reset( $shipping_methods );
                    if ( is_object( $first_shipping ) && method_exists( $first_shipping, 'get_name' ) ) {
                        $carrier_name = (string) $first_shipping->get_name();
                    }
                }
            }
        }

        $carrier_tracking_numbers = array_values( array_unique( array_filter( $carrier_tracking_numbers ) ) );

        // If no Packlink reference and no tracking numbers, this order was not shipped via Packlink
        if ( empty( $reference ) && empty( $carrier_tracking_numbers ) ) {
            return null;
        }

        if ( empty( $currency ) && function_exists( 'get_woocommerce_currency' ) ) {
            $currency = get_woocommerce_currency();
        }

        return [
            'carrier_name'             => $carrier_name,
            'carrier_tracking_numbers' => $carrier_tracking_numbers,
            'carrier_tracking_url'     => $carrier_tracking_url,
            'reference'                => $reference,
            'shipping_price'           => $shipping_price,
            'currency'                 => $currency,
            'status'                   => $status,
        ];
    }

    /**
     * Check if the Packlink PRO Shipping for WooCommerce plugin is active.
     *
     * Checks multiple detection vectors:
     *   1. Standard WordPress active_plugins option (and sitewide active plugins for multisite).
     *   2. is_plugin_active() helper if available.
     *   3. Packlink-specific constants, classes, and functions.
     */
    public static function is_packlink_plugin_active(): bool {
        // 1. Check known plugin file paths in active_plugins option
        $active_plugins = (array) get_option( 'active_plugins', [] );
        if ( is_multisite() ) {
            $network_active = (array) get_site_option( 'active_sitewide_plugins', [] );
            $active_plugins = array_merge( $active_plugins, array_keys( $network_active ) );
        }

        $known_basenames = [
            'packlink-pro-shipping/packlink-pro-shipping.php',
            'packlink-pro-shipping/packlink.php',
            'packlink-pro-shipping/index.php',
            'packlink-pro-shipping/bootstrap.php',
            'packlink-pro/packlink.php',
            'packlink-pro/packlink-pro.php',
        ];

        foreach ( $known_basenames as $basename ) {
            if ( in_array( $basename, $active_plugins, true ) ) {
                return true;
            }
        }

        // Fuzzy match: check if any active plugin entry is inside a packlink directory
        foreach ( $active_plugins as $plugin_file ) {
            if ( stripos( $plugin_file, 'packlink' ) !== false ) {
                return true;
            }
        }

        // 2. Check using WordPress is_plugin_active() helper
        if ( ! function_exists( 'is_plugin_active' ) ) {
            include_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if ( function_exists( 'is_plugin_active' ) ) {
            foreach ( $known_basenames as $basename ) {
                if ( is_plugin_active( $basename ) ) {
                    return true;
                }
            }
        }

        // 3. Check for any defined Packlink constants
        if ( defined( 'PACKLINK_VERSION' )
            || defined( 'PACKLINK_PRO_SHIPPING' )
            || defined( 'PACKLINK_PLUGIN_NAME' )
            || defined( 'PACKLINK_PLUGIN_FILE' )
            || defined( 'PACKLINK_PLUGIN_DIR' )
            || defined( 'PACKLINK_INTEGRATION' )
        ) {
            return true;
        }

        // 4. Check loaded class names across various Packlink versions
        if ( class_exists( 'Packlink\\WooCommerce\\Integration\\Bootstrap' )
            || class_exists( 'Packlink\\WooCommerce\\Bootstrap' )
            || class_exists( 'Packlink\\WooCommerce\\Components\\Bootstrap' )
            || class_exists( 'Packlink\\BusinessLogic\\Bootstrap' )
            || class_exists( 'Packlink_Pro_Shipping' )
            || class_exists( 'PacklinkProShipping' )
            || class_exists( 'Packlink_Shipping_Method' )
            || class_exists( 'WC_Packlink_Shipping' )
        ) {
            return true;
        }

        // 5. Allow external override filter
        return (bool) apply_filters( 'wcotl_is_packlink_active', false );
    }

    /* ------------------------------------------------------------------
     * Internal helpers
     * ------------------------------------------------------------------ */

    /**
     * GET request to Packlink API.
     *
     * @param string $path  Path relative to BASE_URL.
     * @return array{body:array}|WP_Error
     */
    private function request( string $path ) {
        $url      = self::BASE_URL . $path;
        $response = wp_remote_get( $url, [
            'timeout'     => 20,
            'httpversion' => '1.1',
            'headers'     => [
                'Authorization' => $this->api_key,
                'Content-Type'  => 'application/json',
            ],
        ] );

        return $this->parse_response( $response, $url );
    }

    /**
     * Parse a WP HTTP API response into [ 'body' => decoded_array ] | WP_Error.
     */
    private function parse_response( $response, string $request_url = '' ) {
        if ( is_wp_error( $response ) ) {
            $this->debug_log( 'WP_Error: ' . $response->get_error_message()
                . ( $request_url ? " (URL: {$request_url})" : '' ) );
            return $response;
        }

        $code     = wp_remote_retrieve_response_code( $response );
        $body_raw = wp_remote_retrieve_body( $response );

        if ( $code !== 200 ) {
            $body_snippet = mb_substr( $body_raw, 0, 500 );
            $message      = "Packlink HTTP {$code} — body: {$body_snippet}";
            $this->debug_log( $message . ( $request_url ? " (URL: {$request_url})" : '' ) );
            return new WP_Error( 'packlink_http', $message );
        }

        $decoded = json_decode( $body_raw, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            $this->debug_log( 'Invalid JSON from Packlink: ' . mb_substr( $body_raw, 0, 300 ) );
            return new WP_Error( 'packlink_json', 'Invalid JSON from Packlink: ' . mb_substr( $body_raw, 0, 200 ) );
        }

        return [ 'body' => $decoded ];
    }

    /**
     * Convert a Unix timestamp to a MySQL datetime in the WordPress timezone.
     */
    private function format_date( int $ts ): string {
        try {
            $dt = new DateTime( '@' . $ts );
            if ( function_exists( 'wp_timezone' ) ) {
                $dt->setTimezone( wp_timezone() );
            }
            return $dt->format( 'Y-m-d H:i:s' );
        } catch ( Exception $e ) {
            return gmdate( 'Y-m-d H:i:s', $ts );
        }
    }

    private function error_response( string $message ): array {
        return [
            'success' => false,
            'status'  => 'error',
            'events'  => [],
            'raw'     => [],
            'error'   => $message,
        ];
    }

    /**
     * Query shipping rates / available services from Packlink PRO.
     *
     * @param array $origin      [ 'country' => 'IT', 'postcode' => '00100' ]
     * @param array $destination [ 'country' => 'IT', 'postcode' => '20100' ]
     * @param array $parcels     List of [ 'weight' => 1.0, 'length' => 20, 'width' => 20, 'height' => 10 ]
     * @return array Standardized rate results or error info.
     */
    public function get_rates( array $origin, array $destination, array $parcels ): array {
        if ( empty( $this->api_key ) ) {
            return [
                'success' => false,
                'error'   => __( 'Packlink PRO API key is not configured.', 'wc-order-timeline' ),
                'rates'   => [],
            ];
        }

        $from_country = strtoupper( trim( $origin['country'] ?? 'IT' ) );
        $from_zip     = trim( $origin['postcode'] ?? '' );
        $to_country   = strtoupper( trim( $destination['country'] ?? 'IT' ) );
        $to_zip       = trim( $destination['postcode'] ?? '' );

        if ( empty( $from_zip ) || empty( $to_zip ) ) {
            return [
                'success' => false,
                'error'   => __( 'Origin and destination postal codes are required for Packlink rate quotes.', 'wc-order-timeline' ),
                'rates'   => [],
            ];
        }

        $query_params = [
            'from' => [
                'country' => $from_country,
                'zip'     => $from_zip,
            ],
            'to' => [
                'country' => $to_country,
                'zip'     => $to_zip,
            ],
            'packages' => [],
        ];

        foreach ( $parcels as $i => $p ) {
            $query_params['packages'][ (string) $i ] = [
                'width'  => max( 1, (int) ( $p['width'] ?? 15 ) ),
                'height' => max( 1, (int) ( $p['height'] ?? 10 ) ),
                'length' => max( 1, (int) ( $p['length'] ?? 15 ) ),
                'weight' => max( 0.1, (float) ( $p['weight'] ?? 1.0 ) ),
            ];
        }

        if ( empty( $query_params['packages'] ) ) {
            $query_params['packages']['0'] = [
                'width'  => 20,
                'height' => 10,
                'length' => 20,
                'weight' => 1.0,
            ];
        }

        $query_string = http_build_query( $query_params );
        $resp = $this->request( 'services?' . $query_string );

        if ( is_wp_error( $resp ) ) {
            return [
                'success' => false,
                'error'   => 'Packlink error: ' . $resp->get_error_message(),
                'rates'   => [],
            ];
        }

        $body = $resp['body'] ?? [];
        if ( ! is_array( $body ) ) {
            return [
                'success' => false,
                'error'   => 'Packlink returned invalid response.',
                'rates'   => [],
            ];
        }

        $rates = [];
        foreach ( $body as $svc ) {
            if ( ! is_array( $svc ) ) {
                continue;
            }

            $carrier_name = trim( $svc['carrier_name'] ?? $svc['carrier'] ?? 'Packlink' );
            $service_name = trim( $svc['service_name'] ?? $svc['name'] ?? '' );

            // Price extraction
            $price = 0.0;
            if ( isset( $svc['price']['total_price'] ) ) {
                $price = (float) $svc['price']['total_price'];
            } elseif ( isset( $svc['price']['base_price'] ) ) {
                $price = (float) $svc['price']['base_price'];
            } elseif ( isset( $svc['price'] ) && is_numeric( $svc['price'] ) ) {
                $price = (float) $svc['price'];
            } elseif ( isset( $svc['total_price'] ) && is_numeric( $svc['total_price'] ) ) {
                $price = (float) $svc['total_price'];
            }

            $currency = $svc['price']['currency'] ?? $svc['currency'] ?? 'EUR';

            // Transit hours/days
            $transit = '';
            if ( ! empty( $svc['transit_hours'] ) ) {
                $transit = $svc['transit_hours'] . 'h';
            } elseif ( ! empty( $svc['transit_days'] ) ) {
                $transit = $svc['transit_days'] . 'd';
            } elseif ( ! empty( $svc['first_estimated_delivery_date'] ) ) {
                $transit = 'Est. ' . substr( (string) $svc['first_estimated_delivery_date'], 0, 10 );
            }

            $rates[] = [
                'provider'      => 'packlink',
                'provider_name' => 'Packlink PRO',
                'carrier'       => $carrier_name,
                'service_name'  => $service_name,
                'price'         => $price,
                'currency'      => $currency,
                'transit_time'  => $transit,
                'pickup_date'   => $svc['first_estimated_delivery_date'] ?? '',
                'service_id'    => (string) ( $svc['id'] ?? $svc['service_id'] ?? '' ),
                'raw'           => $svc,
            ];
        }

        return [
            'success' => true,
            'rates'   => $rates,
            'error'   => null,
        ];
    }

    /**
     * Write a debug message to the WP error log when WP_DEBUG_LOG is active.
     */
    private function debug_log( string $message ): void {
        if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log( '[WCOTL Packlink] ' . $message );
        }
    }
}

