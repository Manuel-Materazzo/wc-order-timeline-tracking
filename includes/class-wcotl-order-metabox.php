<?php
/**
 * WC order sidebar meta box
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class WCOTL_Order_Metabox {

    public static function init() {
        add_action( 'add_meta_boxes', array( __CLASS__, 'register' ) );
    }

    public static function register() {
        $screens = [ 'shop_order', 'woocommerce_page_wc-orders' ];
        foreach ( $screens as $screen ) {
            add_meta_box(
                'wcotl_order_box',
                'Timeline Tracking',
                array( 'WCOTL_Order_Metabox', 'render' ),
                $screen,
                'side',
                'high'
            );
        }
    }

    public static function render( $post_or_order ) {
        global $wpdb;
        $order_id = is_a( $post_or_order, 'WC_Order' ) ? $post_or_order->get_id() : $post_or_order->ID;
        $table    = $wpdb->prefix . 'order_timeline';

        $codes = $wpdb->get_col(
            $wpdb->prepare( "SELECT DISTINCT tracking_code FROM {$table} WHERE order_id = %d", $order_id )
        );
        ?>
        <p style="font-size:12px;color:#888;margin-bottom:8px;">Tracking codes associated with this order:</p>
        <?php if ( empty($codes) ) : ?>
            <p style="font-size:13px;">None. <a href="<?php echo esc_url( admin_url('admin.php?page=wcotl-new-code&order_id=' . $order_id) ); ?>">Create</a></p>
        <?php else : ?>
            <?php foreach ( $codes as $c ) : ?>
                <p style="margin-bottom:6px;">
                    <code style="background:#f0ede8;padding:2px 6px;border-radius:4px;"><?php echo esc_html($c); ?></code>
                    &nbsp;
                    <a href="<?php echo esc_url( admin_url('admin.php?page=wcotl-tracking&view=' . urlencode($c)) ); ?>" style="font-size:12px;">Manage</a>
                </p>
            <?php endforeach; ?>
        <?php endif; ?>
        <?php
    }

}
