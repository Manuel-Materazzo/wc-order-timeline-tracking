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
        $this->token          = preg_replace( '/^Bearer\s+/i', '', trim( $token ) );
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
                    $this->token = preg_replace( '/^Bearer\s+/i', '', trim( (string) $pf_saved['token'] ) );
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
            $w      = max( 1, (int) ( $p['width'] ?? $p['dim1'] ?? 20 ) );
            $l      = max( 1, (int) ( $p['length'] ?? $p['depth'] ?? $p['dim2'] ?? 20 ) );
            $h      = max( 1, (int) ( $p['height'] ?? $p['dim3'] ?? 10 ) );
            $weight = max( 0.1, (float) ( $p['weight'] ?? 1.0 ) );

            $formatted_parcels[] = [
                'shipment_type' => 1,
                'dim1'          => $w,
                'dim2'          => $l,
                'dim3'          => $h,
                'weight'        => $weight,
                'width'         => $w,
                'length'        => $l,
                'height'        => $h,
            ];
        }

        if ( empty( $formatted_parcels ) ) {
            $formatted_parcels[] = [
                'shipment_type' => 1,
                'dim1'          => 20,
                'dim2'          => 20,
                'dim3'          => 10,
                'weight'        => 1.0,
                'width'         => 20,
                'length'        => 20,
                'height'        => 10,
            ];
        }

        $orig_country = strtoupper( trim( $origin['country'] ?? 'IT' ) );
        $orig_city    = trim( $origin['city'] ?? '' );
        $orig_postcode = trim( $origin['postcode'] ?? '' );
        $orig_state   = trim( $origin['state'] ?? '' );
        if ( class_exists( 'WCOTL_Rate_Comparator' ) && method_exists( 'WCOTL_Rate_Comparator', 'normalize_province' ) ) {
            $orig_state = WCOTL_Rate_Comparator::normalize_province( $orig_state, $orig_country, $orig_city, $orig_postcode );
        }

        $dest_country = strtoupper( trim( $destination['country'] ?? 'IT' ) );
        $dest_city    = trim( $destination['city'] ?? '' );
        $dest_postcode = trim( $destination['postcode'] ?? '' );
        $dest_state   = trim( $destination['state'] ?? '' );
        if ( class_exists( 'WCOTL_Rate_Comparator' ) && method_exists( 'WCOTL_Rate_Comparator', 'normalize_province' ) ) {
            $dest_state = WCOTL_Rate_Comparator::normalize_province( $dest_state, $dest_country, $dest_city, $dest_postcode );
        }

        $payload = [
            'shipment_service' => [
                'shipment_type'        => 1,
                'parcels'              => $formatted_parcels,
                'package_content_type' => 'GOODS',
            ],
            'pickup' => [
                'iso_code'            => $orig_country,
                'postal_code'         => $orig_postcode,
                'city'                => $orig_city,
                'StateOrProvinceCode' => $orig_state,
            ],
            'destination' => [
                'iso_code'            => $dest_country,
                'postal_code'         => $dest_postcode,
                'city'                => $dest_city,
                'StateOrProvinceCode' => $dest_state,
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

        $this->debug_log( 'Quote request payload: ' . wp_json_encode( $payload ) );

        $response = wp_remote_post( $url, [
            'timeout'     => 25,
            'httpversion' => '1.1',
            'headers'     => $headers,
            'body'        => wp_json_encode( $payload ),
        ] );

        if ( is_wp_error( $response ) ) {
            $err = 'Paccofacile request failed: ' . $response->get_error_message();
            $this->debug_log( $err );
            return [
                'success' => false,
                'error'   => $err,
                'rates'   => [],
            ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        $this->debug_log( "Quote response (HTTP {$code}): " . $body );

        // Extract any error messages present in body
        $extracted_error = '';
        if ( is_array( $data ) ) {
            if ( ! empty( $data['header']['notification']['messages']['errors'] ) ) {
                $errs = $data['header']['notification']['messages']['errors'];
                if ( is_array( $errs ) ) {
                    $parts = [];
                    foreach ( $errs as $k => $v ) {
                        $parts[] = is_array( $v ) ? "$k: " . implode( ', ', $v ) : "$k: $v";
                    }
                    $extracted_error = implode( ' | ', $parts );
                } else {
                    $extracted_error = (string) $errs;
                }
            } elseif ( ! empty( $data['header']['notification']['messages']['text'] ) ) {
                $extracted_error = (string) $data['header']['notification']['messages']['text'];
            } elseif ( ! empty( $data['message'] ) ) {
                $extracted_error = (string) $data['message'];
            } elseif ( ! empty( $data['error'] ) ) {
                $extracted_error = is_array( $data['error'] ) ? json_encode( $data['error'] ) : (string) $data['error'];
            } elseif ( ! empty( $data['errors'] ) ) {
                $extracted_error = is_array( $data['errors'] ) ? json_encode( $data['errors'] ) : (string) $data['errors'];
            }
        }

        if ( $code < 200 || $code >= 300 ) {
            $msg = ! empty( $extracted_error ) ? $extracted_error : "HTTP {$code}";
            return [
                'success' => false,
                'error'   => 'Paccofacile error: ' . $msg,
                'rates'   => [],
            ];
        }

        $services = [];
        if ( isset( $data['data']['services_available'] ) && is_array( $data['data']['services_available'] ) ) {
            $services = $data['data']['services_available'];
        } elseif ( isset( $data['services_available'] ) && is_array( $data['services_available'] ) ) {
            $services = $data['services_available'];
        } elseif ( isset( $data['data'] ) && is_array( $data['data'] ) && isset( $data['data'][0] ) ) {
            $services = $data['data'];
        }

        if ( empty( $services ) && ! empty( $extracted_error ) ) {
            return [
                'success' => false,
                'error'   => 'Paccofacile error: ' . $extracted_error,
                'rates'   => [],
            ];
        }

        $rates = [];
        foreach ( $services as $svc ) {
            if ( ! is_array( $svc ) ) {
                continue;
            }

            $carrier_name = trim( $svc['carrier'] ?? $svc['carrier_name'] ?? $svc['corriere'] ?? '' ) ?: 'Paccofacile';
            $service_name = trim( $svc['name'] ?? $svc['service_name'] ?? $svc['description'] ?? '' );
            
            // Extract price
            $price = 0.0;
            if ( isset( $svc['price_total']['amount'] ) && is_numeric( $svc['price_total']['amount'] ) ) {
                $price = (float) $svc['price_total']['amount'];
            } elseif ( isset( $svc['price_total']['taxable_amount'] ) && is_numeric( $svc['price_total']['taxable_amount'] ) ) {
                $price = (float) $svc['price_total']['taxable_amount'];
            } elseif ( isset( $svc['price_total'] ) && is_numeric( $svc['price_total'] ) ) {
                $price = (float) $svc['price_total'];
            } elseif ( isset( $svc['price']['amount'] ) && is_numeric( $svc['price']['amount'] ) ) {
                $price = (float) $svc['price']['amount'];
            } elseif ( isset( $svc['price'] ) && is_numeric( $svc['price'] ) ) {
                $price = (float) $svc['price'];
            } elseif ( isset( $svc['total']['amount'] ) && is_numeric( $svc['total']['amount'] ) ) {
                $price = (float) $svc['total']['amount'];
            } elseif ( isset( $svc['total'] ) && is_numeric( $svc['total'] ) ) {
                $price = (float) $svc['total'];
            }

            $currency = $svc['price_total']['currency'] ?? $svc['currency'] ?? 'EUR';

            // Transit / delivery time
            $transit = $svc['delivery_time'] ?? $svc['transit_time'] ?? '';
            if ( empty( $transit ) && ! empty( $svc['hours'] ) ) {
                $transit = $svc['hours'] . 'h';
            }

            // Pickup date range
            $pickup_date = '';
            if ( ! empty( $svc['pickup_date']['first_date_range'] ) ) {
                $pickup_date = (string) $svc['pickup_date']['first_date_range'];
            } elseif ( ! empty( $svc['pickup_date']['first_date'] ) ) {
                $pickup_date = (string) $svc['pickup_date']['first_date'];
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
                'service_id'    => (string) ( $svc['service_id'] ?? '' ),
                'raw'           => $svc,
            ];
        }

        return [
            'success' => true,
            'rates'   => $rates,
            'error'   => empty( $rates ) ? __( 'Paccofacile returned 0 available quotes for this route and package dimensions.', 'wc-order-timeline' ) : null,
        ];
    }

    /**
     * Write debug messages to error_log when WP_DEBUG_LOG is active.
     */
    private function debug_log( string $message ): void {
        if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log( '[WCOTL Paccofacile] ' . $message );
        }
    }
}
