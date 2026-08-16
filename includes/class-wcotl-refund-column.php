<?php
/**
 * Refund window column in order list
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class WCOTL_Refund_Column {

    public static function init() {
        add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'add_column_cpt' ), 20 );
        add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'render_column_cpt' ), 10, 2 );
        add_filter( 'the_posts', array( __CLASS__, 'prime_orders_cache_cpt' ), 10, 2 );

        add_filter( 'woocommerce_shop_order_list_table_columns', array( __CLASS__, 'add_column_hpos' ), 20 );
        add_action( 'woocommerce_shop_order_list_table_custom_column', array( __CLASS__, 'render_column_hpos' ), 10, 2 );
        add_filter( 'woocommerce_shop_order_list_table_orders', array( __CLASS__, 'prime_orders_cache_hpos' ), 10, 1 );

        add_action( 'admin_head', array( __CLASS__, 'column_styles' ) );
    }

    /**
     * Prime delivered_at cache for CPT order list queries in bulk.
     *
     * @param array $posts
     * @param WP_Query|null $query
     * @return array
     */
    public static function prime_orders_cache_cpt( $posts, $query = null ) {
        if ( ! is_admin() || empty( $posts ) || ! is_array( $posts ) ) {
            return $posts;
        }

        if ( $query instanceof WP_Query && $query->is_main_query() ) {
            $post_type = $query->get( 'post_type' );
            if ( $post_type === 'shop_order' || ( is_array( $post_type ) && in_array( 'shop_order', $post_type, true ) ) ) {
                $order_ids = array();
                foreach ( $posts as $post ) {
                    if ( is_object( $post ) && isset( $post->ID ) ) {
                        $order_ids[] = (int) $post->ID;
                    } elseif ( is_numeric( $post ) ) {
                        $order_ids[] = (int) $post;
                    }
                }
                if ( ! empty( $order_ids ) ) {
                    WCOTL_DB::prime_delivered_at_cache( $order_ids );
                }
            }
        }

        return $posts;
    }

    /**
     * Prime delivered_at cache for HPOS order list table in bulk.
     *
     * @param array $orders
     * @return array
     */
    public static function prime_orders_cache_hpos( $orders ) {
        if ( empty( $orders ) || ! is_array( $orders ) ) {
            return $orders;
        }

        $order_ids = array();
        foreach ( $orders as $order ) {
            if ( is_a( $order, 'WC_Order' ) ) {
                $order_ids[] = (int) $order->get_id();
            } elseif ( is_numeric( $order ) ) {
                $order_ids[] = (int) $order;
            } elseif ( is_object( $order ) && isset( $order->ID ) ) {
                $order_ids[] = (int) $order->ID;
            }
        }

        if ( ! empty( $order_ids ) ) {
            WCOTL_DB::prime_delivered_at_cache( $order_ids );
        }

        return $orders;
    }

    public static function add_column_cpt( $columns ) {
        // Inserisce la colonna dopo "order_status" se presente, altrimenti in fondo
        $pos = array_search( 'order_status', array_keys( $columns ), true );
        if ( $pos !== false ) {
            $pos++; // dopo lo status
            return array_slice( $columns, 0, $pos, true )
                 + [ 'wcotl_refund_window' => __( 'Refund window', 'wc-order-timeline' ) ]
                 + array_slice( $columns, $pos, null, true );
        }
        $columns['wcotl_refund_window'] = __( 'Refund window', 'wc-order-timeline' );
        return $columns;
    }

    public static function render_column_cpt( $column, $post_id ) {
        if ( $column !== 'wcotl_refund_window' ) return;
        WCOTL_Refund_Column::render_cell( $post_id );
    }

    public static function add_column_hpos( $columns ) {
        $pos = array_search( 'order_status', array_keys( $columns ), true );
        if ( $pos !== false ) {
            $pos++;
            return array_slice( $columns, 0, $pos, true )
                 + [ 'wcotl_refund_window' => __( 'Refund window', 'wc-order-timeline' ) ]
                 + array_slice( $columns, $pos, null, true );
        }
        $columns['wcotl_refund_window'] = __( 'Refund window', 'wc-order-timeline' );
        return $columns;
    }

    public static function render_column_hpos( $column, $order ) {
        if ( $column !== 'wcotl_refund_window' ) return;
        $order_id = is_a( $order, 'WC_Order' ) ? $order->get_id() : (int) $order;
        WCOTL_Refund_Column::render_cell( $order_id );
    }

    public static function render_cell( $order_id ) {
        $delivered_at = WCOTL_DB::get_delivered_at_for_order( $order_id );

        if ( ! $delivered_at ) {
            // Nessuna data di consegna registrata
            echo '<span style="color:#b0aaa4;font-size:12px;">—</span>';
            return;
        }

        $days_left = WCOTL_Refund_Column::days_left( $delivered_at );
        $deadline  = ( new DateTime( $delivered_at ) )->modify( '+14 days' )->format( 'd/m/Y' );

        if ( $days_left < 0 ) {
            // Window expired
            printf(
                '<span style="display:inline-flex;align-items:center;gap:4px;'
                . 'background:#fdf2f0;color:#c0392b;border:1px solid #f5b7b1;'
                . 'border-radius:5px;padding:3px 8px;font-size:11px;font-weight:600;'
                . 'letter-spacing:.04em;white-space:nowrap;" title="%s">%s</span>',
                esc_attr( sprintf( __( 'Expired on %s', 'wc-order-timeline' ), $deadline ) ),
                esc_html__( 'Expired', 'wc-order-timeline' )
            );
            return;
        }

        if ( $days_left === 0 ) {
            // Expires today
            printf(
                '<span style="display:inline-flex;align-items:center;gap:4px;'
                . 'background:#fff3cd;color:#856404;border:1px solid #ffc107;'
                . 'border-radius:5px;padding:3px 8px;font-size:11px;font-weight:600;'
                . 'letter-spacing:.04em;white-space:nowrap;" title="%s">%s</span>',
                esc_attr( sprintf( __( 'Expires today (%s)', 'wc-order-timeline' ), $deadline ) ),
                esc_html__( 'Expires today', 'wc-order-timeline' )
            );
            return;
        }

        // Color: green if > 7 days, orange if 1–7 days
        if ( $days_left > 7 ) {
            $bg     = '#eafaf1';
            $color  = '#1e8449';
            $border = '#a9dfbf';
        } else {
            $bg     = '#fff8ee';
            $color  = '#c8963e';
            $border = '#e8c97a';
        }

        printf(
            '<span style="display:inline-flex;align-items:center;gap:4px;'
            . 'background:%s;color:%s;border:1px solid %s;'
            . 'border-radius:5px;padding:3px 8px;font-size:11px;font-weight:600;'
            . 'letter-spacing:.04em;white-space:nowrap;" title="%s">'
            . '%s'
            . '</span>',
            esc_attr( $bg ),
            esc_attr( $color ),
            esc_attr( $border ),
            esc_attr( sprintf( __( 'Expires on %s', 'wc-order-timeline' ), $deadline ) ),
            esc_html( sprintf(
                _n( '%d day', '%d days', $days_left, 'wc-order-timeline' ),
                $days_left
            ) )
        );
    }

    public static function days_left( $delivered_at, $window_days = 14 ) {
        $delivery  = new DateTime( $delivered_at );
        $deadline  = clone $delivery;
        $deadline->modify( "+{$window_days} days" );
        $today     = new DateTime( 'today' );
        $diff      = (int) $today->diff( $deadline )->format( '%r%a' );
        return $diff; // positivo = giorni rimasti, negativo = già scaduto
    }

    public static function column_styles() {
        $screen = get_current_screen();
        if ( ! $screen ) return;
        $is_orders_screen = (
            $screen->id === 'edit-shop_order' ||
            $screen->id === 'woocommerce_page_wc-orders'
        );
        if ( ! $is_orders_screen ) return;
        ?>
        <style>
        .column-wcotl_refund_window { width: 110px; text-align: center !important; }
        th.column-wcotl_refund_window { text-align: center !important; }
        </style>
        <?php
    }

}
