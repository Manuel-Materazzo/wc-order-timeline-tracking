<?php
/**
 * DB creation, migration, meta helpers
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class WCOTL_DB {

    public static function init() {
        add_action( 'admin_init', array( __CLASS__, 'maybe_upgrade' ) );
    }

    public static function activate() {
        global $wpdb;
        $table   = $wpdb->prefix . 'order_timeline';
        $meta    = $wpdb->prefix . 'order_timeline_meta';
        $charset = $wpdb->get_charset_collate();

        $sql_timeline = "CREATE TABLE IF NOT EXISTS {$table} (
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
        ) {$charset};";

        $sql_meta = "CREATE TABLE IF NOT EXISTS {$meta} (
            id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            tracking_code VARCHAR(64)         NOT NULL,
            meta_key      VARCHAR(128)        NOT NULL,
            meta_value    TEXT                         DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY tracking_meta (tracking_code, meta_key)
        ) {$charset};";

        $preset      = $wpdb->prefix . 'order_timeline_presets';
        $sql_presets = "CREATE TABLE IF NOT EXISTS {$preset} (
            id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            preset_name   VARCHAR(128)        NOT NULL,
            step_label    VARCHAR(255)        NOT NULL,
            step_note     TEXT                         DEFAULT NULL,
            step_icon     VARCHAR(64)                  DEFAULT 'truck',
            sort_order    INT(11)             NOT NULL DEFAULT 0,
            created_at    DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql_timeline );
        dbDelta( $sql_meta );
        dbDelta( $sql_presets );

        update_option( 'wcotl_db_version', '1.5.0' );
    }

    public static function maybe_upgrade() {
        if ( get_option( 'wcotl_db_version' ) !== '1.5.0' ) {
            WCOTL_DB::activate();
            // Migrazione: aggiunge le colonne se non esistono già
            global $wpdb;
            $table = $wpdb->prefix . 'order_timeline';
            $cols  = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );
            if ( ! in_array( 'step_voided', $cols, true ) ) {
                $wpdb->query( "ALTER TABLE {$table} ADD COLUMN step_voided TINYINT(1) NOT NULL DEFAULT 0" );
            }
            if ( ! in_array( 'step_void_reason', $cols, true ) ) {
                $wpdb->query( "ALTER TABLE {$table} ADD COLUMN step_void_reason TEXT DEFAULT NULL" );
            }
            if ( ! in_array( 'step_source', $cols, true ) ) {
                $wpdb->query( "ALTER TABLE {$table} ADD COLUMN step_source VARCHAR(16) NOT NULL DEFAULT 'manual'" );
            }
        }
    }

    public static function get_meta( $tracking_code, $key ) {
        global $wpdb;
        $meta = $wpdb->prefix . 'order_timeline_meta';
        return $wpdb->get_var(
            $wpdb->prepare(
                "SELECT meta_value FROM {$meta} WHERE tracking_code = %s AND meta_key = %s",
                $tracking_code, $key
            )
        );
    }

    public static function set_meta( $tracking_code, $key, $value ) {
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
    }

    public static function get_presets() {
        global $wpdb;
        $t = $wpdb->prefix . 'order_timeline_presets';
        return $wpdb->get_results( "SELECT * FROM {$t} ORDER BY sort_order ASC, id ASC" );
    }

    public static function get_delivered_at_for_order( $order_id ) {
        global $wpdb;
        $timeline = $wpdb->prefix . 'order_timeline';
        $meta     = $wpdb->prefix . 'order_timeline_meta';

        return $wpdb->get_var(
            $wpdb->prepare(
                "SELECT m.meta_value
                 FROM {$meta} m
                 INNER JOIN {$timeline} t ON t.tracking_code = m.tracking_code
                 WHERE t.order_id = %d
                   AND m.meta_key   = 'delivered_at'
                   AND m.meta_value != ''
                 LIMIT 1",
                $order_id
            )
        );
    }

}
