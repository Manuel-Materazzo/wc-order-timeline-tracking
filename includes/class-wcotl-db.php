<?php
/**
 * DB creation, migration, meta helpers
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WCOTL_DB {

    /**
     * In-memory cache for delivered_at timestamps indexed by order_id.
     *
     * @var array<int, string|false>
     */
    private static array $delivered_at_cache = array();

    /**
     * In-memory cache for presets.
     *
     * @var array|null
     */
    private static ?array $presets_cache = null;

    public static function init(): void {
        add_action( 'admin_init', array( __CLASS__, 'maybe_upgrade' ) );
    }

    public static function activate(): void {
        global $wpdb;
        $table   = $wpdb->prefix . 'order_timeline';
        $meta    = $wpdb->prefix . 'order_timeline_meta';
        $charset = $wpdb->get_charset_collate();

        $sql_timeline = "CREATE TABLE IF NOT EXISTS $table (
            id               BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            tracking_code    VARCHAR(64)         NOT NULL,
            order_id         BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            step_date        DATETIME            NOT NULL,
            step_label       VARCHAR(255)        NOT NULL,
            step_note        TEXT                         DEFAULT NULL,
            step_icon        VARCHAR(64)                  DEFAULT 'truck',
            step_voided      TINYINT(1)          NOT NULL DEFAULT 0,
            step_void_reason TEXT                         DEFAULT NULL,
            step_source      VARCHAR(16)         NOT NULL DEFAULT 'manual',
            created_at       DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY tracking_code (tracking_code),
            KEY order_id (order_id)
        ) $charset;";

        $sql_meta = "CREATE TABLE IF NOT EXISTS $meta (
            id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            tracking_code VARCHAR(64)         NOT NULL,
            meta_key      VARCHAR(128)        NOT NULL,
            meta_value    TEXT                         DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY tracking_meta (tracking_code, meta_key)
        ) $charset;";

        $preset      = $wpdb->prefix . 'order_timeline_presets';
        $sql_presets = "CREATE TABLE IF NOT EXISTS $preset (
            id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            preset_name   VARCHAR(128)        NOT NULL,
            step_label    VARCHAR(255)        NOT NULL,
            step_note     TEXT                         DEFAULT NULL,
            step_icon     VARCHAR(64)                  DEFAULT 'truck',
            sort_order    INT(11)             NOT NULL DEFAULT 0,
            created_at    DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql_timeline );
        dbDelta( $sql_meta );
        dbDelta( $sql_presets );

        update_option( 'wcotl_db_version', '1.5.1' );
    }

    public static function maybe_upgrade(): void {
        if ( get_option( 'wcotl_db_version' ) !== '1.5.1' ) {
            if ( get_transient( 'wcotl_db_upgrade_lock' ) ) {
                return;
            }
            set_transient( 'wcotl_db_upgrade_lock', 1, HOUR_IN_SECONDS );

            WCOTL_DB::activate();
            // Migrate: add columns that may not exist in older installs.
            global $wpdb;
            $table = $wpdb->prefix . 'order_timeline';
            $meta  = $wpdb->prefix . 'order_timeline_meta';

            $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
            if ( $table_exists === $table ) {
                $timeline_cols = $wpdb->get_col( "SHOW COLUMNS FROM $table" );
                if ( is_array( $timeline_cols ) && empty( $wpdb->last_error ) ) {
                    if ( ! in_array( 'step_voided', $timeline_cols, true ) ) {
                        $wpdb->query( "ALTER TABLE $table ADD COLUMN step_voided TINYINT(1) NOT NULL DEFAULT 0" );
                    }
                    if ( ! in_array( 'step_void_reason', $timeline_cols, true ) ) {
                        $wpdb->query( "ALTER TABLE $table ADD COLUMN step_void_reason TEXT DEFAULT NULL" );
                    }
                    if ( ! in_array( 'step_source', $timeline_cols, true ) ) {
                        $wpdb->query( "ALTER TABLE $table ADD COLUMN step_source VARCHAR(16) NOT NULL DEFAULT 'manual'" );
                    }
                }
            }

            // 1.5.1: per-shipment next_sync_at cursor for the auto-sync engine.
            $meta_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $meta ) );
            if ( $meta_exists === $meta ) {
                $meta_cols = $wpdb->get_col( "SHOW COLUMNS FROM $meta" );
                if ( is_array( $meta_cols ) && empty( $wpdb->last_error ) ) {
                    if ( ! in_array( 'next_sync_at', $meta_cols, true ) ) {
                        $wpdb->query( "ALTER TABLE $meta ADD COLUMN next_sync_at DATETIME DEFAULT NULL" );
                        $wpdb->query( "ALTER TABLE $meta ADD INDEX idx_next_sync_at (next_sync_at)" );
                    }
                }
            }

            delete_transient( 'wcotl_db_upgrade_lock' );
        }
    }

    public static function get_meta( string $tracking_code, string $key ): ?string {
        global $wpdb;
        $meta = $wpdb->prefix . 'order_timeline_meta';
        return $wpdb->get_var(
            $wpdb->prepare(
                "SELECT meta_value FROM $meta WHERE tracking_code = %s AND meta_key = %s",
                $tracking_code, $key
            )
        );
    }

    public static function set_meta( string $tracking_code, string $key, $value ): void {
        global $wpdb;
        $meta = $wpdb->prefix . 'order_timeline_meta';
        if ( $value === '' || $value === null ) {
            $wpdb->delete( $meta, [ 'tracking_code' => $tracking_code, 'meta_key' => $key ] );
        } else {
            $wpdb->replace( $meta, [
                'tracking_code' => $tracking_code,
                'meta_key'      => $key,
                'meta_value'    => $value,
            ] );
        }

        if ( $key === 'delivered_at' ) {
            self::clear_delivered_at_cache();
        }
    }

    public static function clear_delivered_at_cache( $order_id = null ): void {
        if ( null === $order_id ) {
            self::$delivered_at_cache = array();
        } else {
            unset( self::$delivered_at_cache[ absint( $order_id ) ] );
        }
    }

    public static function prime_delivered_at_cache( $order_ids ): void {
        if ( empty( $order_ids ) || ! is_array( $order_ids ) ) {
            return;
        }

        $clean_ids = array();
        foreach ( $order_ids as $id ) {
            $id = absint( $id );
            if ( $id > 0 && ! array_key_exists( $id, self::$delivered_at_cache ) ) {
                $clean_ids[ $id ] = $id;
            }
        }

        if ( empty( $clean_ids ) ) {
            return;
        }

        global $wpdb;
        $timeline = $wpdb->prefix . 'order_timeline';
        $meta     = $wpdb->prefix . 'order_timeline_meta';

        $placeholders = implode( ',', array_fill( 0, count( $clean_ids ), '%d' ) );
        $query        = $wpdb->prepare(
            "SELECT t.order_id, m.meta_value
             FROM $meta m
             INNER JOIN $timeline t ON t.tracking_code = m.tracking_code
             WHERE t.order_id IN ($placeholders)
               AND m.meta_key   = 'delivered_at'
               AND m.meta_value != ''
             ORDER BY m.meta_value DESC",
            array_values( $clean_ids )
        );

        $results = $wpdb->get_results( $query );

        // Initialize all requested IDs as false (not found)
        foreach ( $clean_ids as $id ) {
            self::$delivered_at_cache[ $id ] = false;
        }

        // Populate with the latest delivered_at date found for each order
        if ( ! empty( $results ) ) {
            foreach ( $results as $row ) {
                $oid = (int) $row->order_id;
                // First match is the latest because of ORDER BY m.meta_value DESC
                if ( isset( self::$delivered_at_cache[ $oid ] ) && self::$delivered_at_cache[ $oid ] === false ) {
                    self::$delivered_at_cache[ $oid ] = $row->meta_value;
                }
            }
        }
    }

    public static function clear_presets_cache(): void {
        self::$presets_cache = null;
        wp_cache_delete( 'wcotl_presets', 'wcotl' );
    }

    public static function get_presets(): ?array {
        if ( self::$presets_cache !== null ) {
            return self::$presets_cache;
        }

        $cached = wp_cache_get( 'wcotl_presets', 'wcotl' );
        if ( false !== $cached ) {
            self::$presets_cache = $cached;
            return self::$presets_cache;
        }

        global $wpdb;
        $t = $wpdb->prefix . 'order_timeline_presets';
        $presets = $wpdb->get_results( "SELECT * FROM $t ORDER BY sort_order ASC, id ASC" );
        self::$presets_cache = $presets;
        wp_cache_set( 'wcotl_presets', $presets, 'wcotl', 300 );
        return self::$presets_cache;
    }

    public static function get_delivered_at_for_order( $order_id ): ?string {
        $order_id = absint( $order_id );
        if ( ! $order_id ) {
            return null;
        }

        if ( array_key_exists( $order_id, self::$delivered_at_cache ) ) {
            return self::$delivered_at_cache[ $order_id ] ? self::$delivered_at_cache[ $order_id ] : null;
        }

        self::prime_delivered_at_cache( array( $order_id ) );

        return self::$delivered_at_cache[ $order_id ] ? self::$delivered_at_cache[ $order_id ] : null;
    }

}
