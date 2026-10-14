<?php
/**
 * WC order sidebar meta box
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WCOTL_Order_Metabox {

    public static function init(): void {
        add_action( 'add_meta_boxes', array( __CLASS__, 'register' ) );
    }

    public static function register(): void {
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

    public static function render( $post_or_order ): void {
        global $wpdb;
        $order_id   = is_a( $post_or_order, 'WC_Order' ) ? $post_or_order->get_id() : $post_or_order->ID;
        $codes = WCOTL_DB::get_tracking_codes_for_order( $order_id );

        // Check for Packlink PRO shipping data for this order
        $packlink_data = WCOTL_Packlink_Provider::get_order_packlink_data( $order_id );
        ?>

        <?php if ( $packlink_data ) : ?>
            <div style="background:#f8f9fa;border:1px solid #c3c4c7;border-left:4px solid #2271b1;padding:10px;border-radius:4px;margin-bottom:12px;">
                <div style="font-weight:600;font-size:12px;color:#1d2327;margin-bottom:6px;display:flex;align-items:center;gap:5px;">
                    <span class="dashicons dashicons-cart" style="font-size:16px;width:16px;height:16px;"></span>
                    <span>Packlink PRO Shipping</span>
                </div>
                <div style="font-size:11px;color:#50575e;line-height:1.5;margin-bottom:8px;">
                    <?php if ( ! empty( $packlink_data['carrier_name'] ) ) : ?>
                        <div><strong>Carrier:</strong> <?php echo esc_html( $packlink_data['carrier_name'] ); ?></div>
                    <?php endif; ?>
                    <?php if ( ! empty( $packlink_data['reference'] ) ) : ?>
                        <div><strong>Reference:</strong> <code style="font-size:11px;background:#fff;padding:1px 4px;border:1px solid #ccd0d4;border-radius:3px;"><?php echo esc_html( $packlink_data['reference'] ); ?></code></div>
                    <?php endif; ?>
                    <?php if ( ! empty( $packlink_data['carrier_tracking_numbers'] ) ) : ?>
                        <div><strong>Carrier code(s):</strong>
                            <?php foreach ( $packlink_data['carrier_tracking_numbers'] as $ctn ) : ?>
                                <code style="font-size:11px;background:#fff;padding:1px 4px;border:1px solid #ccd0d4;border-radius:3px;"><?php echo esc_html( $ctn ); ?></code>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <?php if ( ! empty( $packlink_data['shipping_price'] ) && $packlink_data['shipping_price'] > 0 ) : ?>
                        <div><strong>Packlink price:</strong> <?php echo wc_price( $packlink_data['shipping_price'], [ 'currency' => $packlink_data['currency'] ?: 'EUR' ] ); ?></div>
                    <?php endif; ?>
                </div>

                <?php if ( empty( $codes ) ) : ?>
                    <div style="border-top:1px dashed #dcdcde;padding-top:8px;">
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=wcotl-new-code&order_id=' . $order_id ) ); ?>"
                           class="button button-small button-primary" style="width:100%;text-align:center;">
                            + Create Store Tracking Code
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <p style="font-size:12px;color:#888;margin-bottom:8px;">Tracking codes associated with this order:</p>
        <?php if ( empty( $codes ) ) : ?>
            <p style="font-size:13px;">None. <a href="<?php echo esc_url( admin_url( 'admin.php?page=wcotl-new-code&order_id=' . $order_id ) ); ?>">+ Create new code</a></p>
        <?php else : ?>
            <?php foreach ( $codes as $c ) : ?>
                <?php
                $at_provider = WCOTL_DB::get_meta( $c, WCOTL_Auto_Sync::META_PROVIDER );
                $at_real     = WCOTL_DB::get_meta( $c, WCOTL_Auto_Sync::META_REAL_NUMBER );
                ?>
                <div style="margin-bottom:8px;padding-bottom:6px;border-bottom:1px solid #f0f0f1;">
                    <div style="display:flex;justify-content:space-between;align-items:center;">
                        <code style="background:#f0ede8;padding:2px 6px;border-radius:4px;font-weight:600;"><?php echo esc_html( $c ); ?></code>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=wcotl-tracking&view=' . urlencode( $c ) ) ); ?>" class="button button-small" style="font-size:11px;">Manage</a>
                    </div>
                    <?php if ( $at_real ) : ?>
                        <div style="font-size:10px;color:#008a20;margin-top:2px;">
                            ✓ Auto-sync: <?php echo esc_html( ucfirst( $at_provider ?: '17track' ) ); ?>
                        </div>
                    <?php elseif ( $packlink_data && ! empty( $packlink_data['reference'] ) ) : ?>
                        <div style="font-size:10px;margin-top:2px;">
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=wcotl-tracking&view=' . urlencode( $c ) ) ); ?>" style="color:#d63638;">
                                ⚠️ Link Packlink auto-tracking
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <p style="margin-top:8px;font-size:12px;">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wcotl-new-code&order_id=' . $order_id ) ); ?>">+ Add another code</a>
            </p>
        <?php endif; ?>
        <?php
    }

}
