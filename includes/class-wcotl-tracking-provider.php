<?php
/**
 * Abstract Tracking Provider
 *
 * Defines the contract every auto-tracking integration must implement.
 * This abstraction allows future providers (e.g. Aftership, Parcel Monitor)
 * to be added alongside 17track without touching the sync engine.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

abstract class WCOTL_Tracking_Provider {

    /**
     * Human-readable provider name.
     *
     * @return string
     */
    abstract public function get_name(): string;

    /**
     * Whether this provider is properly configured and ready to use.
     *
     * @return bool
     */
    abstract public function is_configured(): bool;

    /**
     * Register a shipment with the provider so it starts tracking.
     *
     * @param string   $real_tracking_number  Carrier tracking number (e.g. "RR123456789CN").
     * @param int|null $carrier_code          Provider-specific carrier numeric code (null = auto-detect).
     * @return array{success:bool, carrier_code:int|null, error:string|null}
     */
    abstract public function register_shipment( string $real_tracking_number, ?int $carrier_code = null ): array;

    /**
     * Fetch the latest tracking events for a shipment.
     *
     * @param string $real_tracking_number
     * @param int    $carrier_code  Provider-specific carrier code (0 = auto).
     * @return array{
     *   success: bool,
     *   status:  string,       // normalised status: 'in_transit'|'delivered'|'expired'|'not_found'|'error'
     *   events:  array,        // [ ['date'=>'Y-m-d H:i:s', 'label'=>string, 'location'=>string|null] ]
     *   raw:     array,        // raw provider response for debugging
     *   error:   string|null
     * }
     */
    abstract public function get_tracking_info( string $real_tracking_number, int $carrier_code = 0 ): array;

    /**
     * Return carrier suggestions for a given tracking number (auto-detect).
     *
     * @param string $real_tracking_number
     * @return array<int, string>  Map of carrier_code => carrier_name
     */
    abstract public function detect_carriers( string $real_tracking_number ): array;
}
