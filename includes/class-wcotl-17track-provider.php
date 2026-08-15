<?php
/**
 * 17track API v2.4 Provider
 *
 * Implements WCOTL_Tracking_Provider using the 17TRACK REST API v2.4.
 * All HTTP calls go through WordPress's wp_remote_post() so the WP
 * HTTP API (proxy settings, SSL config, etc.) is respected.
 *
 * Relevant endpoints:
 *   POST https://api.17track.net/track/v2.4/register        – register a shipment
 *   POST https://api.17track.net/track/v2.4/gettrackinfo    – fetch events
 *   POST https://api.17track.net/track/v2.4/getRealTimeTrackInfo – force real-time pull
 *   GET  https://api.17track.net/track/v2.4/listsearchisomerge?number=… – carrier auto-detect
 *
 * 17TRACK package_status codes mapped to normalised statuses:
 *   0   = Not Found
 *   10  = In Transit
 *   20  = Expired
 *   30  = Pickup
 *   35  = Undelivered
 *   40  = Delivered
 *   50  = Returned
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class WCOTL_17track_Provider extends WCOTL_Tracking_Provider {

    /** API base URL */
    const BASE_URL = 'https://api.17track.net/track/v2.4/';

    /**
     * 17TRACK v2.4 latest_status.status string → normalised status mapping.
     *
     * Actual string values returned by the API (case-sensitive):
     *   NotFound, InfoReceived, InTransit, Expired, AvailableForPickup,
     *   Delivered, UndeliverableFailed, Returning, Returned, Exchanged.
     */
    const STATUS_MAP = [
        'NotFound'              => 'not_found',
        'InfoReceived'          => 'in_transit',
        'InTransit'             => 'in_transit',
        'Expired'               => 'expired',
        'AvailableForPickup'    => 'in_transit',
        'Delivered'             => 'delivered',
        'UndeliverableFailed'   => 'in_transit',
        'Returning'             => 'in_transit',
        'Returned'              => 'in_transit',
        'Exchanged'             => 'in_transit',
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
        return '17TRACK';
    }

    public function is_configured(): bool {
        return $this->api_key !== '';
    }

    /**
     * Register a shipment with 17TRACK.
     */
    public function register_shipment( string $real_tracking_number, ?int $carrier_code = null ): array {
        $payload = [ [ 'number' => $real_tracking_number ] ];
        if ( $carrier_code !== null && $carrier_code > 0 ) {
            $payload[0]['carrier'] = $carrier_code;
        }

        $response = $this->post( 'register', $payload );

        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'carrier_code' => null, 'error' => $response->get_error_message() ];
        }

        $body = $response['body'] ?? [];

        // Check accepted list
        $accepted = $body['data']['accepted'] ?? [];
        if ( ! empty( $accepted ) ) {
            $carrier = isset( $accepted[0]['carrier'] ) ? (int) $accepted[0]['carrier'] : null;
            return [ 'success' => true, 'carrier_code' => $carrier, 'error' => null ];
        }

        // Rejected
        $rejected = $body['data']['rejected'] ?? [];
        $err_msg  = $rejected[0]['error']['message'] ?? 'Registration rejected by 17TRACK.';
        return [ 'success' => false, 'carrier_code' => null, 'error' => $err_msg ];
    }

    /**
     * Fetch latest tracking events.
     */
    public function get_tracking_info( string $real_tracking_number, int $carrier_code = 0 ): array {
        $payload_item = [ 'number' => $real_tracking_number ];
        if ( $carrier_code > 0 ) {
            $payload_item['carrier'] = $carrier_code;
        }

        $response = $this->post( 'gettrackinfo', [ $payload_item ] );

        if ( is_wp_error( $response ) ) {
            return $this->error_response( $response->get_error_message() );
        }

        $body       = $response['body'] ?? [];
        $accepted   = $body['data']['accepted'] ?? [];
        $track_data = $accepted[0] ?? null;

        if ( ! $track_data ) {
            $rejected = $body['data']['rejected'] ?? [];
            $err      = $rejected[0]['error']['message'] ?? 'No data from 17TRACK.';
            return $this->error_response( $err );
        }

        // v2.4: accepted[0].track_info.latest_status.status → string e.g. "InTransit", "Delivered"
        $track_info = $track_data['track_info'] ?? [];
        $status_raw = $track_info['latest_status']['status'] ?? 'NotFound';
        $status     = self::STATUS_MAP[ $status_raw ] ?? 'in_transit';

        // v2.4: accepted[0].track_info.tracking.providers[*].events[]
        $events    = [];
        $seen_keys = [];
        $providers = $track_info['tracking']['providers'] ?? [];
        foreach ( $providers as $provider ) {
            foreach ( $provider['events'] ?? [] as $ev ) {
                // Prefer time_iso (has timezone); fall back to time_utc
                $time_str = $ev['time_iso'] ?? $ev['time_utc'] ?? '';
                $date     = $this->parse_17track_date( $time_str );
                if ( ! $date ) continue;
                $label = sanitize_text_field( $ev['description'] ?? '' );
                if ( empty( $label ) ) continue;
                // Deduplicate across providers
                $key = $date . '|' . $label;
                if ( isset( $seen_keys[ $key ] ) ) continue;
                $seen_keys[ $key ] = true;
                $events[] = [
                    'date'     => $date,
                    'label'    => $label,
                    'location' => ( isset( $ev['location'] ) && $ev['location'] !== '' )
                                    ? sanitize_text_field( $ev['location'] )
                                    : null,
                ];
            }
        }

        usort( $events, fn( $a, $b ) => strcmp( $a['date'], $b['date'] ) );

        return [
            'success' => true,
            'status'  => $status,
            'events'  => $events,
            'raw'     => $track_data,
            'error'   => null,
        ];
    }

    /**
     * Auto-detect carriers for a given tracking number.
     * Uses the /listsearchisomerge endpoint.
     */
    public function detect_carriers( string $real_tracking_number ): array {
        $url      = self::BASE_URL . 'listsearchisomerge?number=' . rawurlencode( $real_tracking_number );
        $response = $this->get( $url );

        if ( is_wp_error( $response ) ) {
            return [];
        }

        $carriers = [];
        $list     = $response['body']['data'] ?? [];

        foreach ( $list as $item ) {
            $code = isset( $item['key'] ) ? (int) $item['key'] : 0;
            $name = $item['value'] ?? '';
            if ( $code && $name ) {
                $carriers[ $code ] = sanitize_text_field( $name );
            }
        }

        return $carriers;
    }

    /* ------------------------------------------------------------------
     * Internal helpers
     * ------------------------------------------------------------------ */

    /**
     * POST request to 17TRACK API.
     *
     * @param string $endpoint  Endpoint path (relative to BASE_URL).
     * @param array  $payload   JSON-serialisable body.
     * @return array{body:array}|WP_Error
     */
    private function post( string $endpoint, array $payload ) {
        $url      = self::BASE_URL . $endpoint;
        $response = wp_remote_post( $url, [
            'timeout'     => 20,
            'headers'     => [
                '17token'      => $this->api_key,
                'Content-Type' => 'application/json',
            ],
            'body'        => wp_json_encode( $payload ),
            'data_format' => 'body',
        ] );

        return $this->parse_response( $response );
    }

    /**
     * GET request to 17TRACK API.
     */
    private function get( string $url ) {
        $response = wp_remote_get( $url, [
            'timeout' => 15,
            'headers' => [
                '17token' => $this->api_key,
            ],
        ] );

        return $this->parse_response( $response );
    }

    /**
     * Parse a WP HTTP API response into [ 'body' => decoded_array ] | WP_Error.
     */
    private function parse_response( $response ) {
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            return new WP_Error( '17track_http', "17TRACK HTTP {$code}" );
        }
        $decoded = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return new WP_Error( '17track_json', 'Invalid JSON from 17TRACK.' );
        }
        return [ 'body' => $decoded ];
    }

    /**
     * Parse a 17TRACK datetime string to MySQL datetime.
     * 17TRACK returns dates in ISO 8601 or custom formats.
     */
    private function parse_17track_date( string $raw ): ?string {
        if ( empty( $raw ) ) return null;
        try {
            $dt = new DateTime( $raw );
            if ( function_exists( 'wp_timezone' ) ) {
                $dt->setTimezone( wp_timezone() );
            }
            return $dt->format( 'Y-m-d H:i:s' );
        } catch ( Exception $e ) {
            return null;
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
}
