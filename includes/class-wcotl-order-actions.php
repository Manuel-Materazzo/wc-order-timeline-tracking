<?php
/**
 * WooCommerce order list table quick action button & batch cache priming.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WCOTL_Order_Actions {

    public static function init(): void {
        // Order action buttons in WooCommerce Order List (Actions column)
        add_filter( 'woocommerce_admin_order_actions', array( __CLASS__, 'add_order_actions' ), 20, 2 );

        // Row action link under order title in classic CPT order list
        add_filter( 'post_row_actions', array( __CLASS__, 'add_post_row_actions' ), 20, 2 );

        // Row action link in HPOS order list
        add_filter( 'woocommerce_summary_order_row_actions', array( __CLASS__, 'add_hpos_row_actions' ), 20, 2 );

        // Prime cache for classic CPT list
        add_action( 'current_screen', function( $screen ) {
            if ( $screen && $screen->id === 'edit-shop_order' ) {
                add_filter( 'the_posts', array( 'WCOTL_Order_Actions', 'prime_orders_cache_cpt' ), 10, 2 );
            }
        } );

        // Prime cache for HPOS list
        add_filter( 'woocommerce_shop_order_list_table_orders', array( __CLASS__, 'prime_orders_cache_hpos' ), 10, 1 );

        // Button styles
        add_action( 'admin_head', array( __CLASS__, 'action_button_styles' ) );
    }

    /**
     * Prime tracking codes cache for CPT order list in bulk.
     *
     * @param array $posts
     * @param WP_Query|null $query
     * @return array
     */
    public static function prime_orders_cache_cpt( array $posts, $query = null ): array {
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
                    WCOTL_DB::prime_tracking_codes_cache( $order_ids );
                }
            }
        }

        return $posts;
    }

    /**
     * Prime tracking codes cache for HPOS order list table in bulk.
     *
     * @param array $orders
     * @return array
     */
    public static function prime_orders_cache_hpos( array $orders ): array {
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
            WCOTL_DB::prime_tracking_codes_cache( $order_ids );
        }

        return $orders;
    }

    /**
     * Add quick action button to the order row in WooCommerce Orders list.
     *
     * @param array $actions Current order action buttons.
     * @param WC_Order|int|object $order The order object or post.
     * @return array
     */
    public static function add_order_actions( array $actions, $order ): array {
        $order_id = is_a( $order, 'WC_Order' ) ? (int) $order->get_id() : (int) ( $order->ID ?? $order );
        if ( ! $order_id ) {
            return $actions;
        }

        $codes = WCOTL_DB::get_tracking_codes_for_order( $order_id );

        if ( ! empty( $codes ) ) {
            $primary_code = $codes[0];
            $count        = count( $codes );
            $label        = $count > 1
                ? sprintf( __( 'Tracking: %s (+%d)', 'wc-order-timeline' ), $primary_code, $count - 1 )
                : sprintf( __( 'Tracking: %s', 'wc-order-timeline' ), $primary_code );
            $tooltip      = $count > 1
                ? sprintf( __( 'Manage tracking: %s (%d tracking codes)', 'wc-order-timeline' ), $primary_code, $count )
                : sprintf( __( 'Manage tracking: %s', 'wc-order-timeline' ), $primary_code );

            $actions['wcotl_tracking'] = array(
                'url'        => admin_url( 'admin.php?page=wcotl-tracking&view=' . urlencode( $primary_code ) ),
                'name'       => $label,
                'action'     => 'wcotl_tracking',
                'aria_label' => $tooltip,
            );
        } else {
            $show_untracked = apply_filters( 'wcotl_order_actions_show_untracked', true, $order_id, $order );
            if ( $show_untracked ) {
                $actions['wcotl_tracking_create'] = array(
                    'url'        => admin_url( 'admin.php?page=wcotl-new-code&order_id=' . $order_id ),
                    'name'       => __( 'Add tracking code', 'wc-order-timeline' ),
                    'action'     => 'wcotl_tracking_create',
                    'aria_label' => sprintf( __( 'Create tracking code for Order #%d', 'wc-order-timeline' ), $order_id ),
                );
            }
        }

        return $actions;
    }

    /**
     * Add tracking edit link to classic CPT row actions (hover links under the order title).
     *
     * @param array $actions
     * @param WP_Post $post
     * @return array
     */
    public static function add_post_row_actions( array $actions, $post ): array {
        if ( ! is_admin() || ! isset( $post->post_type ) || $post->post_type !== 'shop_order' ) {
            return $actions;
        }

        $order_id = (int) $post->ID;
        $codes    = WCOTL_DB::get_tracking_codes_for_order( $order_id );

        if ( ! empty( $codes ) ) {
            $primary_code = $codes[0];
            $url          = admin_url( 'admin.php?page=wcotl-tracking&view=' . urlencode( $primary_code ) );
            $label        = count( $codes ) > 1
                ? sprintf( __( 'Tracking (%d)', 'wc-order-timeline' ), count( $codes ) )
                : __( 'Tracking', 'wc-order-timeline' );
            $actions['wcotl_tracking'] = sprintf(
                '<a href="%s" aria-label="%s">%s</a>',
                esc_url( $url ),
                esc_attr( sprintf( __( 'Manage tracking for Order #%d', 'wc-order-timeline' ), $order_id ) ),
                esc_html( $label )
            );
        }

        return $actions;
    }

    /**
     * Add tracking edit link to HPOS row actions.
     *
     * @param array $actions
     * @param WC_Order $order
     * @return array
     */
    public static function add_hpos_row_actions( array $actions, $order ): array {
        if ( ! is_a( $order, 'WC_Order' ) ) {
            return $actions;
        }

        $order_id = (int) $order->get_id();
        $codes    = WCOTL_DB::get_tracking_codes_for_order( $order_id );

        if ( ! empty( $codes ) ) {
            $primary_code = $codes[0];
            $url          = admin_url( 'admin.php?page=wcotl-tracking&view=' . urlencode( $primary_code ) );
            $label        = count( $codes ) > 1
                ? sprintf( __( 'Tracking (%d)', 'wc-order-timeline' ), count( $codes ) )
                : __( 'Tracking', 'wc-order-timeline' );
            $actions['wcotl_tracking'] = sprintf(
                '<a href="%s" aria-label="%s">%s</a>',
                esc_url( $url ),
                esc_attr( sprintf( __( 'Manage tracking for Order #%d', 'wc-order-timeline' ), $order_id ) ),
                esc_html( $label )
            );
        }

        return $actions;
    }

    /**
     * Output CSS for the quick action buttons in order list.
     */
    public static function action_button_styles(): void {
        $screen = get_current_screen();
        if ( ! $screen ) {
            return;
        }

        $is_orders_screen = (
            $screen->id === 'edit-shop_order' ||
            $screen->id === 'woocommerce_page_wc-orders'
        );
        if ( ! $is_orders_screen ) {
            return;
        }
        ?>
        <style>
        .wc-action-button-wcotl_tracking,
        .wc-action-button-wcotl_tracking_create {
            display: inline-block !important;
            vertical-align: middle;
            position: relative;
            text-indent: -9999px;
        }
        .wc-action-button-wcotl_tracking::after {
            font-family: Dashicons, dashicons;
            content: "\f319" !important; /* dashicons-location-alt */
            speak: never;
            font-weight: 400;
            font-variant: normal;
            text-transform: none;
            line-height: 1.85;
            -webkit-font-smoothing: antialiased;
            margin: 0;
            text-indent: 0;
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            text-align: center;
            color: #2271b1;
        }
        .wc-action-button-wcotl_tracking:hover::after {
            color: #135e96;
        }
        .wc-action-button-wcotl_tracking_create::after {
            font-family: Dashicons, dashicons;
            content: "\f502" !important; /* dashicons-plus-alt */
            speak: never;
            font-weight: 400;
            font-variant: normal;
            text-transform: none;
            line-height: 1.85;
            -webkit-font-smoothing: antialiased;
            margin: 0;
            text-indent: 0;
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            text-align: center;
            color: #8c8f94;
        }
        .wc-action-button-wcotl_tracking_create:hover::after {
            color: #2271b1;
        }
        </style>
        <?php
    }

}
