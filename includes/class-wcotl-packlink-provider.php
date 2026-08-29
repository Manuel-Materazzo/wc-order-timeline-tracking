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
     * @param string   $real_tracking_number  Packlink shipment reference.
     * @param int|null $carrier_code          Unused (Packlink has no carrier codes).
     */
    public function register_shipment( string $real_tracking_number, ?int $carrier_code = null ): array {
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
     * @param string $real_tracking_number  Packlink shipment reference.
     * @param int    $carrier_code          Unused.
     */
    public function get_tracking_info( string $real_tracking_number, int $carrier_code = 0 ): array {
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
     * Write a debug message to the WP error log when WP_DEBUG_LOG is active.
     */
    private function debug_log( string $message ): void {
        if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log( '[WCOTL Packlink] ' . $message );
        }
    }
}
