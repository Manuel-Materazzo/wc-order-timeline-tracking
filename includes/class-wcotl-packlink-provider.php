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
if ( ! defined( 'ABSPATH' ) ) exit;

class WCOTL_Packlink_Provider extends WCOTL_Tracking_Provider {

    /** API base URL */
    const BASE_URL = 'https://api.packlink.com/v1/';

    /**
     * Packlink shipment status string => normalised status mapping.
     */
    const STATUS_MAP = [
        'pending'          => 'in_transit',
        'processing'       => 'in_transit',
        'readyForShipping' => 'in_transit',
        'inTransit'        => 'in_transit',
        'delivered'        => 'delivered',
        'cancelled'        => 'expired',
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
     * Fetch the latest tracking events for a Packlink shipment reference.
     *
     * The tracking-history endpoint returns an array of event objects:
     *   [ { "timestamp": 1234567890, "description": "...", "city": "..." }, ... ]
     *
     * @param string $real_tracking_number  Packlink shipment reference.
     * @param int    $carrier_code          Unused.
     */
    public function get_tracking_info( string $real_tracking_number, int $carrier_code = 0 ): array {
        // First, fetch shipment status.
        $shipment_resp = $this->request( 'shipments/' . rawurlencode( $real_tracking_number ) );
        if ( is_wp_error( $shipment_resp ) ) {
            return $this->error_response( $shipment_resp->get_error_message() );
        }

        $shipment_body   = $shipment_resp['body'] ?? [];
        $packlink_status = $shipment_body['state'] ?? '';
        $status          = self::STATUS_MAP[ $packlink_status ] ?? 'in_transit';

        // Then, fetch tracking-history events.
        $history_resp = $this->request( 'shipments/' . rawurlencode( $real_tracking_number ) . '/tracking-history' );
        if ( is_wp_error( $history_resp ) ) {
            return $this->error_response( $history_resp->get_error_message() );
        }

        $history = $history_resp['body'] ?? [];

        // The endpoint may return an array directly or an object with a 'data' key.
        if ( isset( $history['data'] ) && is_array( $history['data'] ) ) {
            $raw_events = $history['data'];
        } elseif ( is_array( $history ) && ! empty( $history ) && isset( $history[0] ) ) {
            $raw_events = $history;
        } else {
            $raw_events = [];
        }

        $events    = [];
        $seen_keys = [];

        foreach ( $raw_events as $ev ) {
            // Packlink returns unix timestamps; fall back to created_at string.
            $ts = null;
            if ( ! empty( $ev['timestamp'] ) && is_numeric( $ev['timestamp'] ) ) {
                $ts = (int) $ev['timestamp'];
            } elseif ( ! empty( $ev['created_at'] ) ) {
                $ts = strtotime( $ev['created_at'] );
            }

            if ( ! $ts ) continue;

            $date  = $this->format_date( $ts );
            $label = sanitize_text_field( $ev['description'] ?? '' );
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
     * Performed via class_exists so it works reliably after plugins_loaded.
     * We check multiple possible class names to be robust across plugin versions.
     */
    public static function is_packlink_plugin_active(): bool {
        return class_exists( 'Packlink\\WooCommerce\\Integration\\Bootstrap' )
            || class_exists( 'Packlink\\WooCommerce\\Bootstrap' )
            || class_exists( 'Packlink_Pro_Shipping' );
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
