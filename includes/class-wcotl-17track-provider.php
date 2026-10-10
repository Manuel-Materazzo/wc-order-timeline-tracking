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
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

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
     *
     * @param string          $real_tracking_number Carrier tracking number.
     * @param int|string|null $carrier_code         Provider-specific carrier code (null = auto-detect).
     * @return array{success:bool, carrier_code:int|null, error:string|null}
     */
    public function register_shipment( string $real_tracking_number, int|string|null $carrier_code = null ): array {
        $numeric_carrier = ( $carrier_code !== null && is_numeric( $carrier_code ) && (int) $carrier_code > 0 ) ? (int) $carrier_code : null;
        $payload = [ [ 'number' => $real_tracking_number ] ];
        if ( $numeric_carrier !== null ) {
            $payload[0]['carrier'] = $numeric_carrier;
        }

        $response = $this->post( 'register', $payload );

        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'carrier_code' => null, 'error' => $response->get_error_message() ];
        }

        $body = $response['body'] ?? [];

        // Check accepted list
        $accepted = $body['data']['accepted'] ?? [];
        if ( ! empty( $accepted ) ) {
            return [
                'success'      => true,
                'carrier_code' => isset( $accepted[0]['carrier'] ) ? (int) $accepted[0]['carrier'] : null,
                'error'        => null,
            ];
        }

        // Rejected
        $rejected_item = $body['data']['rejected'][0] ?? [];
        $rejected_code = $rejected_item['error']['code'] ?? null;
        $rejected_msg  = $rejected_item['error']['message'] ?? '';

        // 17TRACK error code -18019901: "The tracking number has been registered, don't need to repeat registration."
        // Treat as success so subsequent tracking info calls succeed.
        if ( $rejected_code === -18019901 || stripos( $rejected_msg, 'don\'t need to repeat registration' ) !== false || stripos( $rejected_msg, 'has been registered' ) !== false ) {
            return [
                'success'      => true,
                'carrier_code' => isset( $rejected_item['carrier'] ) ? (int) $rejected_item['carrier'] : ( $numeric_carrier ?: null ),
                'error'        => null,
            ];
        }

        return [
            'success'      => false,
            'carrier_code' => null,
            'error'        => $rejected_msg ?: 'Registration rejected by 17TRACK.',
        ];
    }

    /**
     * Fetch latest tracking events.
     *
     * @param string     $real_tracking_number Carrier tracking number.
     * @param int|string $carrier_code         Provider-specific carrier code (0 = auto).
     * @return array{success:bool, status:string, events:array, raw:array, error:string|null}
     */
    public function get_tracking_info( string $real_tracking_number, int|string $carrier_code = 0 ): array {
        $numeric_carrier = is_numeric( $carrier_code ) ? (int) $carrier_code : 0;
        $payload_item = [ 'number' => $real_tracking_number ];
        if ( $numeric_carrier > 0 ) {
            $payload_item['carrier'] = $numeric_carrier;
        }

        $response = $this->post( 'gettrackinfo', [ $payload_item ] );

        if ( is_wp_error( $response ) ) {
            return $this->error_response( $response->get_error_message() );
        }

        $body     = $response['body'] ?? [];
        $accepted = $body['data']['accepted'] ?? [];
        if ( empty( $accepted[0] ) ) {
            return $this->error_response( $body['data']['rejected'][0]['error']['message'] ?? 'No data from 17TRACK.' );
        }

        $track_data = $accepted[0];
        $track_info = $track_data['track_info'] ?? [];
        $status_raw = $track_info['latest_status']['status'] ?? 'NotFound';

        return [
            'success' => true,
            'status'  => self::STATUS_MAP[ $status_raw ] ?? 'in_transit',
            'events'  => $this->extract_events( $track_info['tracking']['providers'] ?? [] ),
            'raw'     => $track_data,
            'error'   => null,
        ];
    }

    /**
     * Extract and deduplicate tracking events from provider data.
     *
     * @param array $providers
     * @return array
     */
    private function extract_events( array $providers ): array {
        $events    = [];
        $seen_keys = [];

        foreach ( $providers as $provider ) {
            foreach ( $provider['events'] ?? [] as $ev ) {
                $time_str = $ev['time_iso'] ?? $ev['time_utc'] ?? '';
                $date     = $this->parse_17track_date( $time_str );
                $label    = sanitize_text_field( $ev['description'] ?? '' );

                if ( ! $date || empty( $label ) ) {
                    continue;
                }

                $key = $date . '|' . $label;
                if ( isset( $seen_keys[ $key ] ) ) {
                    continue;
                }
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

        return $events;
    }

    /**
     * Auto-detect carriers for a given tracking number.
     * Uses the /listsearchisomerge endpoint.
     *
     * @param string $real_tracking_number
     * @return array<int, string> Map of carrier_code => carrier_name
     */
    public function detect_carriers( string $real_tracking_number ): array {
        $url      = self::BASE_URL . 'listsearchisomerge?number=' . rawurlencode( $real_tracking_number );
        $response = $this->get( $url );

        if ( is_wp_error( $response ) ) {
            return [];
        }

        $carriers = [];
        $list     = $response['body']['data'] ?? [];

        // 17TRACK occasionally returns a non-array value for 'data' (e.g. null or an
        // error string) when no carriers are found. Guard against that here.
        if ( ! is_array( $list ) ) {
            return $carriers;
        }

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
            'httpversion' => '1.1',
            'headers'     => [
                '17token'      => $this->api_key,
                'Content-Type' => 'application/json',
            ],
            'body'        => wp_json_encode( $payload ),
            'data_format' => 'body',
        ] );

        return $this->parse_response( $response, $url );
    }

    /**
     * GET request to 17TRACK API.
     *
     * @param string $url
     * @return array{body:array}|WP_Error
     */
    private function get( string $url ) {
        $response = wp_remote_get( $url, [
            'timeout'     => 15,
            'httpversion' => '1.1',
            'headers'     => [
                '17token' => $this->api_key,
            ],
        ] );

        return $this->parse_response( $response, $url );
    }

    /**
     * Parse a WP HTTP API response into [ 'body' => decoded_array ] | WP_Error.
     *
     * On non-200 responses the raw body (first 500 chars) is included in the
     * WP_Error message so it propagates to the admin UI and debug.log.
     *
     * @param array|WP_Error $response
     * @param string         $request_url
     * @return array{body:array}|WP_Error
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
            // Truncate the body so the error message stays readable.
            $body_snippet = mb_substr( $body_raw, 0, 500 );
            $message      = "17TRACK HTTP {$code} — body: {$body_snippet}";
            $this->debug_log( $message . ( $request_url ? " (URL: {$request_url})" : '' ) );
            return new WP_Error( '17track_http', $message );
        }

        $decoded = json_decode( $body_raw, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            $this->debug_log( 'Invalid JSON from 17TRACK: ' . mb_substr( $body_raw, 0, 300 ) );
            return new WP_Error( '17track_json', 'Invalid JSON from 17TRACK: ' . mb_substr( $body_raw, 0, 200 ) );
        }

        return [ 'body' => $decoded ];
    }

    /**
     * Parse a 17TRACK datetime string to MySQL datetime.
     * 17TRACK returns dates in ISO 8601 or custom formats.
     *
     * @param string $raw
     * @return string|null
     */
    private function parse_17track_date( string $raw ): ?string {
        if ( empty( $raw ) ) {
            return null;
        }
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

    /**
     * Build standard error response array.
     *
     * @param string $message
     * @return array{success:bool, status:string, events:array, raw:array, error:string}
     */
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
     *
     * @param string $message
     * @return void
     */
    private function debug_log( string $message ): void {
        if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log( '[WCOTL 17TRACK] ' . $message );
        }
    }
}

