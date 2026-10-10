<?php
/**
 * FedEx REST API Provider
 *
 * Implements WCOTL_Tracking_Provider using the FedEx REST Track API and OAuth 2.0.
 * All HTTP calls go through WordPress's wp_remote_post() so the WP HTTP API
 * (proxy settings, SSL config, etc.) is respected.
 *
 * Documentation:
 *   - Authorization: https://developer.fedex.com/wirc/browser/en-it/catalog/authorization/docs
 *   - Track API:     https://developer.fedex.com/wirc/browser/en-it/catalog/track/docs
 *
 * Endpoints:
 *   Production:
 *     OAuth 2.0: POST https://apis.fedex.com/oauth/token
 *     Tracking:  POST https://apis.fedex.com/track/v1/trackingnumbers
 *   Sandbox:
 *     OAuth 2.0: POST https://apis-sandbox.fedex.com/oauth/token
 *     Tracking:  POST https://apis-sandbox.fedex.com/track/v1/trackingnumbers
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WCOTL_Fedex_Provider extends WCOTL_Tracking_Provider {

    /** Production API Base URL */
    const BASE_URL_PRODUCTION = 'https://apis.fedex.com/';

    /** Sandbox API Base URL */
    const BASE_URL_SANDBOX = 'https://apis-sandbox.fedex.com/';

    /** OAuth token endpoint path */
    const TOKEN_ENDPOINT = 'oauth/token';

    /** Track API endpoint path */
    const TRACK_ENDPOINT = 'track/v1/trackingnumbers';

    /**
     * Map of FedEx latest status codes / derived codes to normalized statuses.
     */
    const STATUS_MAP = [
        // Standard delivery status codes
        'DL'               => 'delivered',
        'DELIVERED'        => 'delivered',
        'CA'               => 'expired',
        'CANCELLED'        => 'expired',
        'CANCELED'         => 'expired',
        'RS'               => 'expired',
        'RETURNED'         => 'expired',
        // In-transit / active status codes
        'PU'               => 'in_transit',
        'PICKED_UP'        => 'in_transit',
        'IT'               => 'in_transit',
        'IN_TRANSIT'       => 'in_transit',
        'OD'               => 'in_transit',
        'OUT_FOR_DELIVERY' => 'in_transit',
        'OC'               => 'in_transit',
        'LABEL_CREATED'    => 'in_transit',
        'AR'               => 'in_transit',
        'DP'               => 'in_transit',
        'FD'               => 'in_transit',
        'HL'               => 'in_transit',
        'ON_HOLD'          => 'in_transit',
        'DE'               => 'in_transit',
        'SE'               => 'in_transit',
        'EXCEPTION'        => 'in_transit',
        'DY'               => 'in_transit',
        'DELAYED'          => 'in_transit',
    ];

    /** @var string FedEx Client ID / API Key */
    private string $client_id;

    /** @var string FedEx Client Secret / Secret Key */
    private string $client_secret;

    /** @var string 'production' or 'sandbox' */
    private string $environment;

    public function __construct( string $client_id, string $client_secret, string $environment = 'production' ) {
        $this->client_id     = trim( $client_id );
        $this->client_secret = trim( $client_secret );
        $this->environment   = ( $environment === 'sandbox' ) ? 'sandbox' : 'production';
    }

    /* ------------------------------------------------------------------
     * WCOTL_Tracking_Provider implementation
     * ------------------------------------------------------------------ */

    public function get_name(): string {
        return 'FedEx';
    }

    public function is_configured(): bool {
        return $this->client_id !== '' && $this->client_secret !== '';
    }

    /**
     * FedEx does not require pre-registration of tracking numbers.
     * Always returns success.
     *
     * @param string   $real_tracking_number Carrier tracking number.
     * @param int|null $carrier_code         Unused (carrier is FedEx).
     * @return array{success:bool, carrier_code:int|null, error:string|null}
     */
    public function register_shipment( string $real_tracking_number, ?int $carrier_code = null ): array {
        return [
            'success'      => true,
            'carrier_code' => null,
            'error'        => null,
        ];
    }

    /**
     * Fetch latest tracking events from the FedEx Track API.
     *
     * @param string $real_tracking_number Tracking number (e.g. 123456789012).
     * @param int    $carrier_code         Unused.
     * @return array{success:bool, status:string, events:array, raw:array, error:string|null}
     */
    public function get_tracking_info( string $real_tracking_number, int $carrier_code = 0 ): array {
        $clean_number = trim( $real_tracking_number );
        if ( empty( $clean_number ) ) {
            return $this->error_response( 'FedEx tracking number is empty.' );
        }

        // Fetch tracking info with token retry on 401.
        $response = $this->request_tracking( $clean_number );

        if ( is_wp_error( $response ) ) {
            return $this->error_response( $response->get_error_message() );
        }

        $body = $response['body'] ?? [];

        // Check for top-level API errors
        if ( ! empty( $body['errors'] ) && is_array( $body['errors'] ) ) {
            $err_msg = $body['errors'][0]['message'] ?? $body['errors'][0]['code'] ?? 'FedEx API error.';
            return $this->error_response( sanitize_text_field( $err_msg ) );
        }

        // Locate track results: output -> completeTrackResults -> trackResults
        $complete_results = $body['output']['completeTrackResults'] ?? [];
        if ( empty( $complete_results ) || ! is_array( $complete_results ) ) {
            return $this->error_response( 'No tracking results returned by FedEx.' );
        }

        $first_package = $complete_results[0] ?? [];
        $track_results = $first_package['trackResults'] ?? [];

        if ( empty( $track_results ) || ! is_array( $track_results ) ) {
            return $this->error_response( 'No tracking details found for package in FedEx response.' );
        }

        $track_data = $track_results[0];

        // Check package-level error
        if ( ! empty( $track_data['error'] ) && is_array( $track_data['error'] ) ) {
            $err_code = $track_data['error']['code'] ?? '';
            $err_msg  = $track_data['error']['message'] ?? 'Tracking number error.';

            if ( stripos( $err_code, 'NOTFOUND' ) !== false || stripos( $err_msg, 'not be found' ) !== false ) {
                return [
                    'success' => true,
                    'status'  => 'not_found',
                    'events'  => [],
                    'raw'     => $track_data,
                    'error'   => sanitize_text_field( $err_msg ),
                ];
            }

            return $this->error_response( sanitize_text_field( $err_msg ) );
        }

        // Normalize status
        $status = $this->determine_status( $track_data );

        // Extract scan events
        $events = $this->extract_events( $track_data['scanEvents'] ?? [] );

        // Fallback: verify delivery from scan events or dateAndTimes
        if ( $status !== 'delivered' ) {
            if ( $this->has_delivery_event( $events, $track_data ) ) {
                $status = 'delivered';
            }
        }

        return [
            'success' => true,
            'status'  => $status,
            'events'  => $events,
            'raw'     => $track_data,
            'error'   => null,
        ];
    }

    /**
     * Carrier detection for FedEx.
     * Since this provider handles FedEx shipments, returns FedEx.
     *
     * @param string $real_tracking_number
     * @return array<int, string>
     */
    public function detect_carriers( string $real_tracking_number ): array {
        return [
            1 => 'FedEx',
        ];
    }

    /* ------------------------------------------------------------------
     * Tracking extraction & normalization helpers
     * ------------------------------------------------------------------ */

    /**
     * Determine normalized status ('in_transit'|'delivered'|'expired'|'not_found')
     * from track data.
     *
     * @param array $track_data
     * @return string
     */
    private function determine_status( array $track_data ): string {
        $latest = $track_data['latestStatusDetail'] ?? [];
        $code   = strtoupper( trim( (string) ( $latest['code'] ?? '' ) ) );
        $derived = strtoupper( trim( (string) ( $latest['derivedCode'] ?? '' ) ) );
        $desc   = strtolower( trim( (string) ( $latest['description'] ?? $latest['statusByLocale'] ?? '' ) ) );

        if ( isset( self::STATUS_MAP[ $code ] ) ) {
            return self::STATUS_MAP[ $code ];
        }

        if ( isset( self::STATUS_MAP[ $derived ] ) ) {
            return self::STATUS_MAP[ $derived ];
        }

        if ( str_contains( $desc, 'delivered' ) || str_contains( $desc, 'consegnato' ) || str_contains( $desc, 'entregado' ) ) {
            return 'delivered';
        }

        if ( str_contains( $desc, 'cancel' ) || str_contains( $desc, 'return to shipper' ) || str_contains( $desc, 'expired' ) ) {
            return 'expired';
        }

        return 'in_transit';
    }

    /**
     * Check if events or dateAndTimes indicate delivery.
     *
     * @param array $events
     * @param array $track_data
     * @return bool
     */
    private function has_delivery_event( array $events, array $track_data ): bool {
        // 1. Check dateAndTimes
        foreach ( $track_data['dateAndTimes'] ?? [] as $dt ) {
            $type = strtoupper( trim( (string) ( $dt['type'] ?? '' ) ) );
            if ( $type === 'ACTUAL_DELIVERY' ) {
                return true;
            }
        }

        // 2. Check event labels
        foreach ( $events as $ev ) {
            $label = strtolower( $ev['label'] ?? '' );
            if (
                str_contains( $label, 'delivered' ) ||
                str_contains( $label, 'consegnato' ) ||
                str_contains( $label, 'entregado' ) ||
                str_contains( $label, 'livré' ) ||
                str_contains( $label, 'zugestellt' )
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract and normalize scan events from scanEvents array.
     *
     * @param array $raw_events
     * @return array<int, array{date:string, label:string, location:string|null}>
     */
    private function extract_events( array $raw_events ): array {
        $events    = [];
        $seen_keys = [];

        foreach ( $raw_events as $ev ) {
            if ( ! is_array( $ev ) ) {
                continue;
            }

            $raw_date = (string) ( $ev['date'] ?? '' );
            $date     = $this->parse_date( $raw_date );
            if ( ! $date ) {
                continue;
            }

            $label = sanitize_text_field( $ev['eventDescription'] ?? $ev['derivedStatus'] ?? $ev['eventType'] ?? '' );
            if ( empty( $label ) ) {
                continue;
            }

            // Deduplicate
            $key = $date . '|' . $label;
            if ( isset( $seen_keys[ $key ] ) ) {
                continue;
            }
            $seen_keys[ $key ] = true;

            $location = $this->format_scan_location( $ev['scanLocation'] ?? [] );

            $events[] = [
                'date'     => $date,
                'label'    => $label,
                'location' => $location,
            ];
        }

        // Sort events chronologically ascending
        usort( $events, fn( $a, $b ) => strcmp( $a['date'], $b['date'] ) );

        return $events;
    }

    /**
     * Format scan location into a readable string (e.g. "MEMPHIS, TN, US").
     *
     * @param array $loc
     * @return string|null
     */
    private function format_scan_location( array $loc ): ?string {
        if ( empty( $loc ) || ! is_array( $loc ) ) {
            return null;
        }

        $parts = [];
        if ( ! empty( $loc['city'] ) ) {
            $parts[] = sanitize_text_field( $loc['city'] );
        }
        if ( ! empty( $loc['stateOrProvinceCode'] ) ) {
            $parts[] = sanitize_text_field( $loc['stateOrProvinceCode'] );
        }
        if ( ! empty( $loc['countryCode'] ) ) {
            $parts[] = sanitize_text_field( $loc['countryCode'] );
        }

        if ( empty( $parts ) ) {
            return null;
        }

        return implode( ', ', $parts );
    }

    /**
     * Convert an ISO 8601 date string to MySQL datetime in WordPress timezone.
     *
     * @param string $raw
     * @return string|null
     */
    private function parse_date( string $raw ): ?string {
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

    /* ------------------------------------------------------------------
     * HTTP & OAuth Token Handling
     * ------------------------------------------------------------------ */

    /**
     * Return the base URL according to the configured environment.
     *
     * @return string
     */
    private function get_base_url(): string {
        return ( $this->environment === 'sandbox' ) ? self::BASE_URL_SANDBOX : self::BASE_URL_PRODUCTION;
    }

    /**
     * Transient key for caching OAuth access tokens.
     *
     * @return string
     */
    private function get_token_cache_key(): string {
        return 'wcotl_fedex_token_' . md5( $this->client_id . '|' . $this->environment );
    }

    /**
     * Get a valid OAuth 2.0 Bearer access token.
     * Uses WordPress transients to cache the token for its validity window.
     *
     * @param bool $force_refresh
     * @return string|WP_Error
     */
    public function get_access_token( bool $force_refresh = false ) {
        $cache_key = $this->get_token_cache_key();

        if ( ! $force_refresh ) {
            $cached = get_transient( $cache_key );
            if ( ! empty( $cached ) && is_string( $cached ) ) {
                return $cached;
            }
        }

        $url = $this->get_base_url() . self::TOKEN_ENDPOINT;

        $response = wp_remote_post( $url, [
            'timeout'     => 20,
            'httpversion' => '1.1',
            'headers'     => [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body'        => http_build_query( [
                'grant_type'    => 'client_credentials',
                'client_id'     => $this->client_id,
                'client_secret' => $this->client_secret,
            ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            $this->debug_log( 'Token error: ' . $response->get_error_message() );
            return $response;
        }

        $code     = wp_remote_retrieve_response_code( $response );
        $body_raw = wp_remote_retrieve_body( $response );

        if ( $code !== 200 ) {
            $body_snippet = mb_substr( $body_raw, 0, 500 );
            $message      = "FedEx OAuth failed (HTTP {$code}): {$body_snippet}";
            $this->debug_log( $message );
            return new WP_Error( 'fedex_auth_error', $message );
        }

        $decoded = json_decode( $body_raw, true );
        if ( json_last_error() !== JSON_ERROR_NONE || empty( $decoded['access_token'] ) ) {
            $this->debug_log( 'Invalid token response JSON from FedEx' );
            return new WP_Error( 'fedex_token_json', 'Invalid token response JSON from FedEx.' );
        }

        $token      = (string) $decoded['access_token'];
        $expires_in = isset( $decoded['expires_in'] ) ? (int) $decoded['expires_in'] : 3600;

        // Cache with a 2-minute safety margin
        $ttl = max( 60, $expires_in - 120 );
        set_transient( $cache_key, $token, $ttl );

        return $token;
    }

    /**
     * Clear cached access token.
     *
     * @return void
     */
    public function clear_cached_token(): void {
        delete_transient( $this->get_token_cache_key() );
    }

    /**
     * Send tracking request to FedEx, retrying once on 401 Unauthorized.
     *
     * @param string $tracking_number
     * @param bool   $is_retry
     * @return array{body:array}|WP_Error
     */
    private function request_tracking( string $tracking_number, bool $is_retry = false ) {
        $token = $this->get_access_token( $is_retry );
        if ( is_wp_error( $token ) ) {
            return $token;
        }

        $url     = $this->get_base_url() . self::TRACK_ENDPOINT;
        $payload = [
            'includeDetailedScans' => true,
            'trackingInfo'         => [
                [
                    'trackingNumberInfo' => [
                        'trackingNumber' => $tracking_number,
                    ],
                ],
            ],
        ];

        $response = wp_remote_post( $url, [
            'timeout'     => 20,
            'httpversion' => '1.1',
            'headers'     => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
                'X-locale'      => 'en_US',
            ],
            'body'        => wp_json_encode( $payload ),
            'data_format' => 'body',
        ] );

        if ( is_wp_error( $response ) ) {
            $this->debug_log( 'WP_Error: ' . $response->get_error_message() . " (URL: {$url})" );
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );

        // If 401 Unauthorized and not already retrying, invalidate token and retry once
        if ( $code === 401 && ! $is_retry ) {
            $this->debug_log( 'Received 401 from FedEx Track API, refreshing token...' );
            $this->clear_cached_token();
            return $this->request_tracking( $tracking_number, true );
        }

        $body_raw = wp_remote_retrieve_body( $response );

        if ( $code !== 200 ) {
            $body_snippet = mb_substr( $body_raw, 0, 500 );
            $message      = "FedEx HTTP {$code} — body: {$body_snippet}";
            $this->debug_log( $message . " (URL: {$url})" );
            return new WP_Error( 'fedex_http', $message );
        }

        $decoded = json_decode( $body_raw, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            $this->debug_log( 'Invalid JSON from FedEx: ' . mb_substr( $body_raw, 0, 300 ) );
            return new WP_Error( 'fedex_json', 'Invalid JSON from FedEx: ' . mb_substr( $body_raw, 0, 200 ) );
        }

        return [ 'body' => $decoded ];
    }

    /**
     * Standard error response array.
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
            error_log( '[WCOTL FedEx] ' . $message );
        }
    }
}
