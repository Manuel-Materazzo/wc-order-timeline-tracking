<?php
/**
 * Paccofacile.it API Provider for Rate Quoting
 *
 * Handles authentication and quote calculation via the Paccofacile.it REST API.
 * All HTTP calls use wp_remote_post() to respect WordPress HTTP configuration.
 *
 * Endpoints:
 *   Production: https://paccofacile.tecnosogima.cloud/live/v1/service/shipment/quote?field_pickup_date=1
 *   Sandbox:    https://paccofacile.tecnosogima.cloud/sandbox/v1/service/shipment/quote?field_pickup_date=1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WCOTL_Paccofacile_Provider {

    const LIVE_BASE_URL    = 'https://paccofacile.tecnosogima.cloud/live/v1/service/';
    const SANDBOX_BASE_URL = 'https://paccofacile.tecnosogima.cloud/sandbox/v1/service/';

    private string $account_number;
    private string $api_key;
    private string $token;
    private string $environment;

    public function __construct(
        string $account_number = '',
        string $api_key = '',
        string $token = '',
        string $environment = 'live'
    ) {
        $this->account_number = trim( $account_number );
        $this->api_key        = trim( $api_key );
        $this->token          = trim( $token );
        $this->environment    = ( $environment === 'sandbox' ) ? 'sandbox' : 'live';

        // Auto-detect credentials from official 'paccofacile-for-woocommerce' plugin if options not explicitly set
        if ( empty( $this->account_number ) || empty( $this->api_key ) || empty( $this->token ) ) {
            $pf_saved = get_option( 'paccofacile_settings', [] );
            if ( is_array( $pf_saved ) ) {
                if ( empty( $this->account_number ) && ! empty( $pf_saved['account_number'] ) ) {
                    $this->account_number = trim( (string) $pf_saved['account_number'] );
                }
                if ( empty( $this->api_key ) && ! empty( $pf_saved['api_key'] ) ) {
                    $this->api_key = trim( (string) $pf_saved['api_key'] );
                }
                if ( empty( $this->token ) && ! empty( $pf_saved['token'] ) ) {
                    $this->token = trim( (string) $pf_saved['token'] );
                }
            }
        }
    }

    /**
     * Check if Paccofacile is configured with all 3 required keys.
     */
    public function is_configured(): bool {
        return ! empty( $this->account_number )
            && ! empty( $this->api_key )
            && ! empty( $this->token );
    }

    /**
     * Check if official Paccofacile for WooCommerce plugin is installed/active.
     */
    public static function is_paccofacile_plugin_active(): bool {
        if ( class_exists( 'Paccofacile' ) || class_exists( 'PFWC_Paccofacile_Api' ) ) {
            return true;
        }

        if ( ! function_exists( 'is_plugin_active' ) ) {
            include_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if ( function_exists( 'is_plugin_active' ) ) {
            if ( is_plugin_active( 'paccofacile-for-woocommerce/paccofacile-for-woocommerce.php' ) ) {
                return true;
            }
        }

        $active_plugins = (array) get_option( 'active_plugins', [] );
        foreach ( $active_plugins as $p ) {
            if ( stripos( $p, 'paccofacile' ) !== false ) {
                return true;
            }
        }

        return false;
    }

    public function get_name(): string {
        return 'Paccofacile.it';
    }

    public function get_base_url(): string {
        return ( $this->environment === 'sandbox' ) ? self::SANDBOX_BASE_URL : self::LIVE_BASE_URL;
    }

    /**
     * Query shipping rates for an origin, destination, and package list.
     *
     * @param array $origin      [ 'country' => 'IT', 'postcode' => '00100', 'city' => 'Roma', 'state' => 'RM' ]
     * @param array $destination [ 'country' => 'IT', 'postcode' => '20100', 'city' => 'Milano', 'state' => 'MI' ]
     * @param array $parcels     List of [ 'weight' => 1.0, 'length' => 20, 'width' => 20, 'height' => 10 ]
     * @return array Standardized rate results or error info.
     */
    public function get_rates( array $origin, array $destination, array $parcels ): array {
        if ( ! $this->is_configured() ) {
            return [
                'success' => false,
                'error'   => __( 'Paccofacile API credentials (Account Number, API Key, Token) are not fully configured.', 'wc-order-timeline' ),
                'rates'   => [],
            ];
        }

        $formatted_parcels = [];
        foreach ( $parcels as $p ) {
            $formatted_parcels[] = [
                'weight' => max( 0.1, (float) ( $p['weight'] ?? 1.0 ) ),
                'length' => max( 1, (int) ( $p['length'] ?? 15 ) ),
                'width'  => max( 1, (int) ( $p['width'] ?? 15 ) ),
                'height' => max( 1, (int) ( $p['height'] ?? 10 ) ),
            ];
        }
        if ( empty( $formatted_parcels ) ) {
            $formatted_parcels[] = [
                'weight' => 1.0,
                'length' => 20,
                'width'  => 20,
                'height' => 10,
            ];
        }

        $payload = [
            'shipment_service' => [
                'parcels'              => $formatted_parcels,
                'package_content_type' => 'GOODS',
            ],
            'pickup' => [
                'iso_code'            => strtoupper( trim( $origin['country'] ?? 'IT' ) ),
                'postal_code'         => trim( $origin['postcode'] ?? '' ),
                'city'                => trim( $origin['city'] ?? '' ),
                'StateOrProvinceCode' => strtoupper( trim( $origin['state'] ?? '' ) ),
            ],
            'destination' => [
                'iso_code'            => strtoupper( trim( $destination['country'] ?? 'IT' ) ),
                'postal_code'         => trim( $destination['postcode'] ?? '' ),
                'city'                => trim( $destination['city'] ?? '' ),
                'StateOrProvinceCode' => strtoupper( trim( $destination['state'] ?? '' ) ),
            ],
        ];

        $headers = [
            'Content-Type'   => 'application/json',
            'Account-Number' => $this->account_number,
            'api-key'        => $this->api_key,
            'Authorization'  => 'Bearer ' . $this->token,
            'Session-Id'     => 'wcotl-rates-compare',
        ];

        $url = $this->get_base_url() . 'shipment/quote?field_pickup_date=1';

        $response = wp_remote_post( $url, [
            'timeout'     => 25,
            'httpversion' => '1.1',
            'headers'     => $headers,
            'body'        => wp_json_encode( $payload ),
        ] );

        if ( is_wp_error( $response ) ) {
            return [
                'success' => false,
                'error'   => 'Paccofacile request failed: ' . $response->get_error_message(),
                'rates'   => [],
            ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( $code < 200 || $code >= 300 ) {
            $msg = is_array( $data ) && ! empty( $data['message'] ) ? $data['message'] : "HTTP {$code}";
            return [
                'success' => false,
                'error'   => 'Paccofacile error: ' . $msg,
                'rates'   => [],
            ];
        }

        $services = $data['data']['services_available'] ?? $data['services_available'] ?? [];
        if ( ! is_array( $services ) ) {
            $services = [];
        }

        $rates = [];
        foreach ( $services as $svc ) {
            $carrier_name = trim( $svc['carrier'] ?? '' ) ?: 'Paccofacile';
            $service_name = trim( $svc['name'] ?? '' );
            
            // Extract price
            $price = 0.0;
            if ( isset( $svc['price_total']['amount'] ) ) {
                $price = (float) $svc['price_total']['amount'];
            } elseif ( isset( $svc['price_total'] ) && is_numeric( $svc['price_total'] ) ) {
                $price = (float) $svc['price_total'];
            } elseif ( isset( $svc['price'] ) && is_numeric( $svc['price'] ) ) {
                $price = (float) $svc['price'];
            } elseif ( isset( $svc['total'] ) && is_numeric( $svc['total'] ) ) {
                $price = (float) $svc['total'];
            }

            $currency = $svc['price_total']['currency'] ?? 'EUR';

            // Transit / delivery time
            $transit = $svc['delivery_time'] ?? $svc['transit_time'] ?? '';
            if ( empty( $transit ) && ! empty( $svc['hours'] ) ) {
                $transit = $svc['hours'] . 'h';
            }

            // Pickup date range
            $pickup_date = '';
            if ( ! empty( $svc['pickup_date']['first_date_range'] ) ) {
                $pickup_date = (string) $svc['pickup_date']['first_date_range'];
            }

            $rates[] = [
                'provider'      => 'paccofacile',
                'provider_name' => 'Paccofacile.it',
                'carrier'       => $carrier_name,
                'service_name'  => $service_name,
                'price'         => $price,
                'currency'      => $currency,
                'transit_time'  => $transit,
                'pickup_date'   => $pickup_date,
                'service_id'    => $svc['service_id'] ?? '',
                'raw'           => $svc,
            ];
        }

        return [
            'success' => true,
            'rates'   => $rates,
            'error'   => null,
        ];
    }
}
