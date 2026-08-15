<?php
/**
 * Auto-sync engine
 *
 * Responsible for:
 *   - Registering/de-registering the WP-Cron hook.
 *   - On each cron tick, iterating over all active tracking codes
 *     that have a real carrier tracking number attached, fetching
 *     updates from the configured provider, and writing new timeline
 *     steps into the database.
 *   - Stopping polling when a shipment reaches a terminal state
 *     (delivered, expired, or 45 days without a status update).
 *   - Sending an admin email when a shipment is stopped due to the
 *     45-day inactivity rule.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class WCOTL_Auto_Sync {

    /** WP-Cron hook name */
    const CRON_HOOK = 'wcotl_auto_sync';

    /** Meta key: real carrier tracking number set by admin */
    const META_REAL_NUMBER = 'auto_tracking_number';

    /** Meta key: carrier code (int) stored after first registration */
    const META_CARRIER_CODE = 'auto_tracking_carrier';

    /** Meta key: flag indicating this code is registered at the provider */
    const META_REGISTERED = 'auto_tracking_registered';

    /** Meta key: normalised status returned by the last provider call */
    const META_LAST_STATUS = 'auto_tracking_last_status';

    /** Meta key: datetime of last event found for this shipment (MySQL) */
    const META_LAST_EVENT_DATE = 'auto_tracking_last_event_date';

    /** Meta key: datetime of first registration (MySQL) */
    const META_FIRST_REGISTERED = 'auto_tracking_first_registered';

    /** Meta key: flag to stop auto-syncing ('1' = stopped) */
    const META_SYNC_STOPPED = 'auto_tracking_stopped';

    /** Meta key: reason sync was stopped */
    const META_STOP_REASON = 'auto_tracking_stop_reason';

    /** Days of inactivity before we stop polling */
    const DEFAULT_INACTIVITY_DAYS = 45;

    /* ------------------------------------------------------------------ */

    public static function init() {
        add_action( self::CRON_HOOK, [ __CLASS__, 'run_sync' ] );
        add_action( 'update_option_wcotl_sync_interval', [ __CLASS__, 'reschedule' ], 10, 2 );
        add_action( 'plugins_loaded', [ __CLASS__, 'ensure_scheduled' ] );
        add_filter( 'cron_schedules', [ __CLASS__, 'add_cron_schedules' ] );
    }

    /**
     * Make sure the cron job is scheduled with the current interval.
     */
    public static function ensure_scheduled() {
        $interval = self::get_interval_slug();
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), $interval, self::CRON_HOOK );
        }
    }

    /**
     * Called when the sync interval option changes – reschedule.
     */
    public static function reschedule( $old_value, $new_value ) {
        $timestamp = wp_next_scheduled( self::CRON_HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_HOOK );
        }
        wp_schedule_event( time(), self::get_interval_slug(), self::CRON_HOOK );
    }

    /**
     * Remove cron job on plugin deactivation.
     */
    public static function deactivate() {
        $timestamp = wp_next_scheduled( self::CRON_HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_HOOK );
        }
    }

    /* ------------------------------------------------------------------
     * Core sync
     * ------------------------------------------------------------------ */

    /**
     * Meta key: datetime at which this tracking code should next be synced.
     * NULL or past means "due now". Set to NOW()+interval after each sync.
     */
    const META_NEXT_SYNC_AT = 'auto_tracking_next_sync_at';

    /**
     * Main sync method – executed by WP-Cron.
     *
     * Uses a per-shipment `next_sync_at` cursor so every active tracking code
     * is guaranteed to be processed in turn regardless of catalog size:
     *
     *   - The query selects only codes that are *due* (next_sync_at <= NOW()
     *     or never synced yet), ordered oldest-due first.
     *   - After each successful sync the code's next_sync_at is pushed forward
     *     by the configured interval, so it won't be re-queried until then.
     *   - The loop runs until the PHP execution time budget is exhausted.
     *     Any codes not reached this tick will simply be first in line next
     *     tick (their next_sync_at has not been advanced, so they stay at the
     *     front of the queue).
     *
     * Result: one knob (sync interval) means one thing ("how often each
     * shipment is checked"), no silent skipping, no coupled batch/interval
     * parameters.
     */
    public static function run_sync() {
        $provider = self::get_provider();
        if ( ! $provider || ! $provider->is_configured() ) {
            return;
        }

        global $wpdb;
        $meta_table = $wpdb->prefix . 'order_timeline_meta';

        // How many seconds per tick we are willing to spend.
        $max_execution = (int) ini_get( 'max_execution_time' );
        if ( $max_execution <= 0 || $max_execution > 120 ) {
            $time_budget = 50; // conservative default for unlimited / very long limits
        } else {
            $time_budget = max( 10, $max_execution - 10 );
        }

        $start = microtime( true );

        // How far ahead to schedule the next sync for each processed shipment.
        $interval_seconds = max( 1, (int) get_option( 'wcotl_sync_interval', 1 ) ) * HOUR_IN_SECONDS;

        /*
         * Pull codes that are due for a sync, oldest-due first.
         * "Due" means next_sync_at IS NULL (never synced) or <= NOW().
         * We use a LEFT JOIN so that codes with no next_sync_at row at all
         * are also included (NULL IS NULL => due).
         * Sync-stopped codes are excluded via a NOT EXISTS sub-select.
         *
         * No LIMIT – we process as many as the time budget allows, and
         * every code we process gets its next_sync_at advanced, so it
         * won't appear in the query again until its next due time.
         */
        $codes = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT m.tracking_code
                 FROM {$meta_table} m
                 LEFT JOIN {$meta_table} ns
                        ON ns.tracking_code = m.tracking_code
                       AND ns.meta_key = %s
                 WHERE m.meta_key = %s
                   AND m.meta_value != ''
                   AND ( ns.meta_value IS NULL OR ns.meta_value <= %s )
                   AND NOT EXISTS (
                       SELECT 1 FROM {$meta_table} st
                       WHERE st.tracking_code = m.tracking_code
                         AND st.meta_key = %s
                         AND st.meta_value = '1'
                   )
                 ORDER BY ns.meta_value ASC",
                self::META_NEXT_SYNC_AT,
                self::META_REAL_NUMBER,
                current_time( 'mysql' ),
                self::META_SYNC_STOPPED
            )
        );

        foreach ( $codes as $tracking_code ) {
            if ( ( microtime( true ) - $start ) >= $time_budget ) {
                // Time budget exhausted. This code's next_sync_at has NOT been
                // advanced, so it will be first in the queue next tick.
                break;
            }

            self::sync_code( $tracking_code, $provider );

            // Advance the per-shipment cursor regardless of whether sync_code
            // found new events – we still checked, that counts as a sync.
            WCOTL_DB::set_meta(
                $tracking_code,
                self::META_NEXT_SYNC_AT,
                gmdate( 'Y-m-d H:i:s', time() + $interval_seconds )
            );
        }
    }

    /**
     * Sync a single tracking code.
     */
    public static function sync_code( string $tracking_code, ?WCOTL_Tracking_Provider $provider = null ) {
        if ( ! $provider ) {
            $provider = self::get_provider();
            if ( ! $provider || ! $provider->is_configured() ) return;
        }

        $real_number  = WCOTL_DB::get_meta( $tracking_code, self::META_REAL_NUMBER );
        if ( ! $real_number ) return;

        $carrier_code = (int) ( WCOTL_DB::get_meta( $tracking_code, self::META_CARRIER_CODE ) ?: 0 );
        $registered   = WCOTL_DB::get_meta( $tracking_code, self::META_REGISTERED );

        // Register with provider if not done yet.
        if ( ! $registered ) {
            $reg = $provider->register_shipment( $real_number, $carrier_code ?: null );
            if ( ! $reg['success'] ) {
                return; // Will retry on next cron tick.
            }
            if ( $reg['carrier_code'] ) {
                WCOTL_DB::set_meta( $tracking_code, self::META_CARRIER_CODE, $reg['carrier_code'] );
                $carrier_code = (int) $reg['carrier_code'];
            }
            WCOTL_DB::set_meta( $tracking_code, self::META_REGISTERED, '1' );
            WCOTL_DB::set_meta( $tracking_code, self::META_FIRST_REGISTERED, current_time( 'mysql' ) );
        }

        // Fetch latest events.
        $info = $provider->get_tracking_info( $real_number, $carrier_code );
        if ( ! $info['success'] ) {
            return;
        }

        $status = $info['status']; // 'in_transit'|'delivered'|'expired'|'not_found'|'error'
        WCOTL_DB::set_meta( $tracking_code, self::META_LAST_STATUS, $status );

        // Insert new events into the timeline.
        $last_event_date = self::insert_new_events( $tracking_code, $info['events'] );
        if ( $last_event_date ) {
            WCOTL_DB::set_meta( $tracking_code, self::META_LAST_EVENT_DATE, $last_event_date );
        }

        // Handle terminal states.
        if ( $status === 'delivered' ) {
            // Set the delivered_at meta from the last event date.
            $delivered_date = $last_event_date
                ? ( new DateTime( $last_event_date ) )->format( 'Y-m-d' )
                : current_time( 'Y-m-d' );
            WCOTL_DB::set_meta( $tracking_code, 'delivered_at', $delivered_date );
            self::stop_sync( $tracking_code, 'delivered' );
            return;
        }

        if ( $status === 'expired' ) {
            self::stop_sync( $tracking_code, 'expired' );
            return;
        }

        // Check 45-day inactivity rule.
        $inactivity_days = (int) get_option( 'wcotl_inactivity_days', self::DEFAULT_INACTIVITY_DAYS );
        $last_event = WCOTL_DB::get_meta( $tracking_code, self::META_LAST_EVENT_DATE );
        if ( $last_event ) {
            $diff_days = (int) ( ( time() - strtotime( $last_event ) ) / DAY_IN_SECONDS );
            if ( $diff_days >= $inactivity_days ) {
                self::stop_sync( $tracking_code, 'inactivity', $diff_days );
                self::send_inactivity_email( $tracking_code, $real_number, $diff_days );
            }
        }
    }

    /**
     * Insert new events (those that don't already exist) into the timeline.
     *
     * @param string $tracking_code
     * @param array  $events  [ ['date'=>'Y-m-d H:i:s', 'label'=>string, 'location'=>string|null] ]
     * @return string|null  The latest event datetime inserted (or null if nothing new).
     */
    private static function insert_new_events( string $tracking_code, array $events ): ?string {
        if ( empty( $events ) ) return null;

        global $wpdb;
        $table = $wpdb->prefix . 'order_timeline';

        // Load existing AUTO-SYNCED step dates+labels to avoid duplicates.
        $existing = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT step_date, step_label FROM {$table}
                 WHERE tracking_code = %s AND step_source = 'auto'",
                $tracking_code
            )
        );
        $existing_keys = [];
        foreach ( $existing as $row ) {
            $existing_keys[ $row->step_date . '|' . $row->step_label ] = true;
        }

        // Get order_id for new rows.
        $order_id = absint( WCOTL_DB::get_meta( $tracking_code, 'order_id' ) ?: 0 );
        if ( ! $order_id ) {
            $existing_order_id = $wpdb->get_var(
                $wpdb->prepare( "SELECT order_id FROM {$table} WHERE tracking_code = %s AND order_id > 0 LIMIT 1", $tracking_code )
            );
            if ( $existing_order_id ) {
                $order_id = absint( $existing_order_id );
                WCOTL_DB::set_meta( $tracking_code, 'order_id', $order_id );
            }
        }

        $latest_date = null;

        foreach ( $events as $ev ) {
            $key = $ev['date'] . '|' . $ev['label'];
            if ( isset( $existing_keys[ $key ] ) ) continue;
            if ( empty( $ev['label'] ) ) continue;

            $note = $ev['location'] ? $ev['location'] : null;

            $wpdb->insert( $table, [
                'tracking_code' => $tracking_code,
                'order_id'      => $order_id,
                'step_date'     => $ev['date'],
                'step_label'    => $ev['label'],
                'step_note'     => $note,
                'step_icon'     => 'truck',
                'step_source'   => 'auto',
            ] );

            if ( ! $latest_date || $ev['date'] > $latest_date ) {
                $latest_date = $ev['date'];
            }
        }

        return $latest_date;
    }

    /**
     * Mark a tracking code's auto-sync as stopped.
     */
    private static function stop_sync( string $tracking_code, string $reason, int $days = 0 ) {
        WCOTL_DB::set_meta( $tracking_code, self::META_SYNC_STOPPED, '1' );
        $reason_text = match( $reason ) {
            'delivered'  => 'Shipment delivered.',
            'expired'    => '17TRACK marked shipment as expired.',
            'inactivity' => "No status update for {$days} days.",
            default      => $reason,
        };
        WCOTL_DB::set_meta( $tracking_code, self::META_STOP_REASON, $reason_text );
    }

    /**
     * Send an admin email when polling stops due to inactivity.
     */
    private static function send_inactivity_email( string $tracking_code, string $real_number, int $days ) {
        $to      = get_option( 'admin_email' );
        $subject = sprintf(
            '[%s] Auto-tracking stopped: %s (no update for %d days)',
            get_bloginfo( 'name' ),
            $tracking_code,
            $days
        );
        $message = sprintf(
            "Auto-tracking has been stopped for the following shipment because no status update was received for %d days:\n\n"
            . "  Plugin tracking code : %s\n"
            . "  Carrier tracking #   : %s\n\n"
            . "Please check the carrier website manually or resume tracking from the plugin admin.\n\n"
            . "Manage this code: %s",
            $days,
            $tracking_code,
            $real_number,
            admin_url( 'admin.php?page=wcotl-tracking&view=' . rawurlencode( $tracking_code ) )
        );
        wp_mail( $to, $subject, $message );
    }

    /* ------------------------------------------------------------------
     * Helper: get active provider
     * ------------------------------------------------------------------ */

    /**
     * Build and return the configured tracking provider, or null.
     *
     * @return WCOTL_Tracking_Provider|null
     */
    public static function get_provider(): ?WCOTL_Tracking_Provider {
        $api_key = get_option( 'wcotl_17track_api_key', '' );
        if ( ! $api_key ) return null;
        return new WCOTL_17track_Provider( $api_key );
    }

    /* ------------------------------------------------------------------
     * Cron interval helper
     * ------------------------------------------------------------------ */

    /**
     * Return the WP-Cron interval slug matching the stored interval option.
     */
    public static function get_interval_slug(): string {
        $hours = max( 1, (int) get_option( 'wcotl_sync_interval', 1 ) );
        return 'wcotl_every_' . $hours . 'h';
    }

    /**
     * Register all required custom intervals with WP-Cron.
     */
    public static function add_cron_schedules( array $schedules ): array {
        $hours = max( 1, (int) get_option( 'wcotl_sync_interval', 1 ) );
        $slug  = 'wcotl_every_' . $hours . 'h';
        if ( ! isset( $schedules[ $slug ] ) ) {
            $schedules[ $slug ] = [
                'interval' => $hours * HOUR_IN_SECONDS,
                'display'  => sprintf( 'Every %d hour(s)', $hours ),
            ];
        }
        return $schedules;
    }
}
