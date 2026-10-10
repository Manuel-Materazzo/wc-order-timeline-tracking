<?php
/**
 * Track123 API Provider
 *
 * Implements WCOTL_Tracking_Provider using the Track123 REST API v2.1.
 * All HTTP calls go through WordPress's wp_remote_request() so the WP
 * HTTP API (proxy settings, SSL config, etc.) is respected.
 *
 * Relevant endpoints:
 *   POST https://api.track123.com/gateway/open-api/tk/v2.1/track/import    – register/import shipment
 *   POST https://api.track123.com/gateway/open-api/tk/v2.1/track/query     – query tracking data & checkpoints
 *   POST https://api.track123.com/gateway/open-api/tk/v2.1/courier/detection – carrier auto-detect
 *   GET  https://api.track123.com/gateway/open-api/tk/v2.1/courier/list    – list all supported package carriers
 *
 * Track123 transit statuses mapped to normalised statuses:
 *   INIT             => in_transit
 *   NO_RECORD        => not_found
 *   INFO_RECEIVED    => in_transit
 *   IN_TRANSIT       => in_transit
 *   WAITING_DELIVERY => in_transit
 *   DELIVERY_FAILED  => in_transit
 *   ABNORMAL         => in_transit
 *   DELIVERED        => delivered
 *   EXPIRED          => expired
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WCOTL_Track123_Provider extends WCOTL_Tracking_Provider {

    /** API base URL */
    const BASE_URL = 'https://api.track123.com/gateway/open-api/';

    /** Endpoints */
    const IMPORT_ENDPOINT       = 'tk/v2.1/track/import';
    const QUERY_ENDPOINT        = 'tk/v2.1/track/query';
    const DETECTION_ENDPOINT    = 'tk/v2.1/courier/detection';
    const CARRIER_LIST_ENDPOINT = 'tk/v2.1/courier/list';

    /**
     * Track123 transit status string → normalised status mapping.
     */
    const STATUS_MAP = [
        'INIT'             => 'in_transit',
        'NO_RECORD'        => 'not_found',
        'INFO_RECEIVED'    => 'in_transit',
        'IN_TRANSIT'       => 'in_transit',
        'WAITING_DELIVERY' => 'in_transit',
        'DELIVERY_FAILED'  => 'in_transit',
        'ABNORMAL'         => 'in_transit',
        'DELIVERED'        => 'delivered',
        'EXPIRED'          => 'expired',
    ];

    /** @var string Track123 API Key / Secret */
    private string $api_key;

    public function __construct( string $api_key ) {
        $this->api_key = trim( $api_key );
    }

    /* ------------------------------------------------------------------
     * WCOTL_Tracking_Provider implementation
     * ------------------------------------------------------------------ */

    public function get_name(): string {
        return 'Track123';
    }

    public function is_configured(): bool {
        return $this->api_key !== '';
    }

    /**
     * Register / import a shipment with Track123.
     *
     * @param string          $real_tracking_number Carrier tracking number.
     * @param int|string|null $carrier_code         Carrier slug (e.g. 'fedex', 'usps') or null for auto-detect.
     * @return array{success:bool, carrier_code:int|string|null, error:string|null}
     */
    public function register_shipment( string $real_tracking_number, int|string|null $carrier_code = null ): array {
        $clean_number = trim( $real_tracking_number );
        if ( empty( $clean_number ) ) {
            return [
                'success'      => false,
                'carrier_code' => null,
                'error'        => 'Tracking number is empty.',
            ];
        }

        $item = [ 'trackNo' => $clean_number ];
        if ( ! empty( $carrier_code ) ) {
            $item['courierCode'] = (string) $carrier_code;
        }

        $response = $this->request( self::IMPORT_ENDPOINT, 'POST', [ $item ] );

        if ( is_wp_error( $response ) ) {
            return [
                'success'      => false,
                'carrier_code' => null,
                'error'        => $response->get_error_message(),
            ];
        }

        $data     = $response['data'] ?? [];
        $accepted = $data['accepted'] ?? [];

        // Successfully registered/imported
        if ( ! empty( $accepted ) ) {
            $assigned_carrier = $accepted[0]['courierCode'] ?? null;
            return [
                'success'      => true,
                'carrier_code' => $assigned_carrier ?: ( $carrier_code ?: null ),
                'error'        => null,
            ];
        }

        // Check rejected list
        $rejected_item = $data['rejected'][0] ?? [];
        $rejected_msg  = $rejected_item['error']['msg'] ?? $rejected_item['error']['message'] ?? '';

        // If already imported/registered, treat as success so subsequent tracking info calls proceed.
        if ( stripos( $rejected_msg, 'imported' ) !== false
          || stripos( $rejected_msg, 'already' ) !== false
          || stripos( $rejected_msg, 'repeat' ) !== false
          || stripos( $rejected_msg, 'registered' ) !== false
          || stripos( $rejected_msg, 'exist' ) !== false ) {
            return [
                'success'      => true,
                'carrier_code' => $rejected_item['courierCode'] ?? ( $carrier_code ?: null ),
                'error'        => null,
            ];
        }

        $error_message = $rejected_msg ?: ( $response['msg'] ?? 'Registration rejected by Track123.' );
        return [
            'success'      => false,
            'carrier_code' => null,
            'error'        => $error_message,
        ];
    }

    /**
     * Fetch latest tracking events from Track123.
     *
     * @param string     $real_tracking_number Carrier tracking number.
     * @param int|string $carrier_code         Carrier code/slug if known, 0 or empty for auto.
     * @return array{success:bool, status:string, events:array, raw:array, error:string|null}
     */
    public function get_tracking_info( string $real_tracking_number, int|string $carrier_code = 0 ): array {
        $clean_number = trim( $real_tracking_number );
        if ( empty( $clean_number ) ) {
            return $this->error_response( 'Tracking number is empty.' );
        }

        $item = [ 'trackNo' => $clean_number ];
        if ( ! empty( $carrier_code ) ) {
            $item['courierCode'] = (string) $carrier_code;
        }

        $payload = [
            'trackNoInfos' => [ $item ],
        ];

        $response = $this->request( self::QUERY_ENDPOINT, 'POST', $payload );

        if ( is_wp_error( $response ) ) {
            return $this->error_response( $response->get_error_message() );
        }

        $service_code = $response['code'] ?? '';
        if ( $service_code !== '00000' ) {
            $msg = $response['msg'] ?? ( 'Track123 error code ' . $service_code );
            return $this->error_response( $msg );
        }

        $data    = $response['data'] ?? [];
        $content = [];
        if ( isset( $data['accepted']['content'] ) && is_array( $data['accepted']['content'] ) ) {
            $content = $data['accepted']['content'];
        } elseif ( isset( $data['accepted'] ) && is_array( $data['accepted'] ) ) {
            $content = $data['accepted'];
        }

        if ( empty( $content[0] ) ) {
            $rejected_msg = $data['rejected'][0]['error']['msg'] ?? $response['msg'] ?? 'No tracking data returned by Track123.';
            return $this->error_response( $rejected_msg );
        }

        $track_data     = $content[0];
        $transit_status = strtoupper( trim( $track_data['transitStatus'] ?? '' ) );
        $query_status   = trim( $track_data['trackingStatus'] ?? '' );

        if ( $transit_status === 'DELIVERED' ) {
            $status = 'delivered';
        } elseif ( $transit_status === 'EXPIRED' ) {
            $status = 'expired';
        } elseif ( $transit_status === 'NO_RECORD' || $query_status === '002' ) {
            $status = 'not_found';
        } else {
            $status = self::STATUS_MAP[ $transit_status ] ?? 'in_transit';
        }

        $events = $this->extract_events( $track_data );

        return [
            'success' => true,
            'status'  => $status,
            'events'  => $events,
            'raw'     => $track_data,
            'error'   => null,
        ];
    }

    /**
     * Auto-detect carriers for a tracking number using Track123.
     *
     * @param string $real_tracking_number Carrier tracking number.
     * @return array<string, string> Map of courier_code => courier_name
     */
    public function detect_carriers( string $real_tracking_number ): array {
        $clean_number = trim( $real_tracking_number );
        if ( empty( $clean_number ) ) {
            return [];
        }

        $response = $this->request( self::DETECTION_ENDPOINT, 'POST', [
            'tracking_number' => $clean_number,
        ] );

        if ( is_wp_error( $response ) || ( $response['code'] ?? '' ) !== '00000' ) {
            return [];
        }

        $data       = $response['data'] ?? [];
        $candidates = $data['candidates'] ?? [];
        if ( empty( $candidates ) && ! empty( $data['recommended_carrier'] ) ) {
            $candidates = [ $data['recommended_carrier'] ];
        }

        $courier_names = $this->get_courier_name_map();
        $result        = [];

        foreach ( $candidates as $candidate ) {
            $code = $candidate['carrier'] ?? '';
            if ( ! $code ) {
                continue;
            }
            $name = $courier_names[ $code ] ?? strtoupper( $code );
            $result[ $code ] = $name;
        }

        return $result;
    }

    /**
     * Get the full list of package couriers supported by Track123 (cached via transient).
     *
     * @return array
     */
    public function get_courier_list(): array {
        $transient_key = 'wcotl_track123_couriers';
        $cached        = get_transient( $transient_key );
        if ( is_array( $cached ) && ! empty( $cached ) ) {
            return $cached;
        }

        $response = $this->request( self::CARRIER_LIST_ENDPOINT, 'GET' );
        if ( is_wp_error( $response ) || ( $response['code'] ?? '' ) !== '00000' ) {
            return is_array( $cached ) ? $cached : [];
        }

        $list = $response['data'] ?? [];
        if ( is_array( $list ) && ! empty( $list ) ) {
            set_transient( $transient_key, $list, 7 * DAY_IN_SECONDS );
            return $list;
        }

        return [];
    }

    /**
     * Helper to return map of courierCode => English (or primary) courier name.
     *
     * @return array<string, string>
     */
    public function get_courier_name_map(): array {
        $list = $this->get_courier_list();
        $map  = [];
        foreach ( $list as $item ) {
            $code = $item['courierCode'] ?? '';
            $name = $item['courierNameEN'] ?? $item['courierNameCN'] ?? $code;
            if ( $code ) {
                $map[ $code ] = $name;
            }
        }
        return $map;
    }

    /* ------------------------------------------------------------------
     * Private helpers
     * ------------------------------------------------------------------ */

    /**
     * Extract and deduplicate events from Track123 shipment data.
     *
     * @param array $track_data
     * @return array
     */
    private function extract_events( array $track_data ): array {
        $events    = [];
        $seen_keys = [];

        // Check localLogisticsInfo
        $checkpoint_groups = [];
        if ( ! empty( $track_data['localLogisticsInfo']['trackingDetails'] ) && is_array( $track_data['localLogisticsInfo']['trackingDetails'] ) ) {
            $checkpoint_groups[] = $track_data['localLogisticsInfo']['trackingDetails'];
        }

        // Check lastMileInfo (openApiWayBillInfo or direct trackingDetails)
        if ( ! empty( $track_data['lastMileInfo']['openApiWayBillInfo']['trackingDetails'] ) && is_array( $track_data['lastMileInfo']['openApiWayBillInfo']['trackingDetails'] ) ) {
            $checkpoint_groups[] = $track_data['lastMileInfo']['openApiWayBillInfo']['trackingDetails'];
        } elseif ( ! empty( $track_data['lastMileInfo']['trackingDetails'] ) && is_array( $track_data['lastMileInfo']['trackingDetails'] ) ) {
            $checkpoint_groups[] = $track_data['lastMileInfo']['trackingDetails'];
        }

        // Check originLogisticsInfo if present
        if ( ! empty( $track_data['originLogisticsInfo']['trackingDetails'] ) && is_array( $track_data['originLogisticsInfo']['trackingDetails'] ) ) {
            $checkpoint_groups[] = $track_data['originLogisticsInfo']['trackingDetails'];
        }

        foreach ( $checkpoint_groups as $group ) {
            foreach ( $group as $detail ) {
                $time_str = $detail['eventTime'] ?? $detail['eventTimeZeroUTC'] ?? '';
                $date     = $this->parse_date( $time_str );
                $label    = sanitize_text_field( $detail['eventDetail'] ?? '' );
                $address  = sanitize_text_field( $detail['address'] ?? '' );
                $location = ( $address !== '' ) ? $address : null;

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
                    'location' => $location,
                ];
            }
        }

        usort( $events, fn( $a, $b ) => strcmp( $a['date'], $b['date'] ) );

        return $events;
    }

    /**
     * Parse date string into standard MySQL format 'Y-m-d H:i:s'.
     *
     * @param string $time_str
     * @return string|null
     */
    private function parse_date( string $time_str ): ?string {
        if ( empty( $time_str ) ) {
            return null;
        }
        try {
            $dt = new DateTime( $time_str );
            return $dt->format( 'Y-m-d H:i:s' );
        } catch ( Exception ) {
            return null;
        }
    }

    /**
     * Standard error response array.
     *
     * @param string $message
     * @return array
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
     * Execute HTTP request against Track123 API.
     *
     * @param string $endpoint Relative endpoint path.
     * @param string $method   'POST' or 'GET'.
     * @param array  $body     Request body for POST.
     * @return array|WP_Error  Decoded response array or WP_Error.
     */
    private function request( string $endpoint, string $method = 'POST', array $body = [] ): array|WP_Error {
        $url     = self::BASE_URL . ltrim( $endpoint, '/' );
        $headers = [
            'Track123-Api-Secret' => $this->api_key,
            'Content-Type'        => 'application/json',
            'Accept'              => 'application/json',
        ];

        $args = [
            'method'  => $method,
            'headers' => $headers,
            'timeout' => 20,
        ];

        if ( $method === 'POST' ) {
            $args['body'] = wp_json_encode( $body );
        }

        $response = wp_remote_request( $url, $args );
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code     = wp_remote_retrieve_response_code( $response );
        $raw_body = wp_remote_retrieve_body( $response );
        $decoded  = json_decode( $raw_body, true );

        if ( $code < 200 || $code >= 300 ) {
            $msg = ( is_array( $decoded ) && ! empty( $decoded['msg'] ) )
                ? $decoded['msg']
                : ( 'HTTP ' . $code . ' response from Track123' );
            return new WP_Error( 'track123_http_error_' . $code, $msg, [ 'status' => $code, 'body' => $decoded ] );
        }

        if ( ! is_array( $decoded ) ) {
            return new WP_Error( 'track123_json_error', 'Invalid JSON response from Track123' );
        }

        return $decoded;
    }
}
