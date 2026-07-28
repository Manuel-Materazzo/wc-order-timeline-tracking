<?php
/**
 * Frontend shortcode
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class WCOTL_Shortcode {

    public static function init() {
        add_shortcode( 'wc_order_timeline_tracking', array( __CLASS__, 'render' ) );
    }

public static function render() {
    ob_start();

    $code   = isset( $_GET['tracking'] ) ? sanitize_text_field( wp_unslash( $_GET['tracking'] ) ) : '';
    $steps  = [];
    $meta   = null;
    $error  = '';

    if ( $code !== '' ) {
        global $wpdb;
        $table = $wpdb->prefix . 'order_timeline';

        $steps = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE tracking_code = %s ORDER BY step_date ASC",
                $code
            )
        );

        if ( empty( $steps ) ) {
            $error = 'Nessun ordine trovato per il codice <strong>' . esc_html( $code ) . '</strong>. Verifica il codice e riprova.';
        }
    }

    $estimated_delivery = ( $code !== '' ) ? WCOTL_DB::get_meta( $code, 'estimated_delivery' ) : null;
    $delivered_at       = ( $code !== '' ) ? WCOTL_DB::get_meta( $code, 'delivered_at' )       : null;

    // Icone SVG inline per i tipi comuni
    $icons = WCOTL_Icons::map();
    ?>
    <!– ——— STILI ——— –>
    <style>
    :root {
        --otl-bg:        #ffffff;
        --otl-surface:   #ffffff;
        --otl-brand:     #1a1a2e;
        --otl-accent:    #c8963e;
        --otl-accent2:   #e8b86d;
        --otl-text:      #2d2d2d;
        --otl-muted:     #888580;
        --otl-border:    #e2ddd8;
        --otl-line:      #d4cfc9;
        --otl-radius:    14px;
        --otl-shadow:    0 4px 32px rgba(0,0,0,.08);
    }

    .wcotl-wrap *,
    .wcotl-wrap *::before,
    .wcotl-wrap *::after { box-sizing: border-box; margin:0; padding:0; }

    .wcotl-wrap {
        background: var(--otl-bg);
        min-height: 60vh;
        padding: 48px 16px 80px;
        color: var(--otl-text);
    }

    .wcotl-container {
        max-width: 680px;
        margin: 0 auto;
    }

    /* Header */
    .wcotl-header {
        text-align: center;
        margin-bottom: 40px;
    }
    .wcotl-header__eyebrow {
        display: inline-block;
        font-size: 11px;
        letter-spacing: .18em;
        text-transform: uppercase;
        color: var(--otl-accent);
        margin-bottom: 12px;
    }
    .wcotl-header h1 {
        font-size: clamp(26px, 5vw, 38px);
        font-weight: normal;
        color: var(--otl-brand);
        line-height: 1.15;
        letter-spacing: -.5px;
    }
    .wcotl-header p {
        margin-top: 10px;
        color: var(--otl-muted);
        font-size: 15px;
        font-style: italic;
    }

    /* Form di ricerca */
    .wcotl-search {
        background: var(--otl-surface);
        border: 1px solid var(--otl-border);
        border-radius: var(--otl-radius);
        padding: 28px 32px;
        box-shadow: var(--otl-shadow);
        margin-bottom: 36px;
    }
    .wcotl-search label {
        display: block;
        font-size: 11px;
        letter-spacing: .15em;
        text-transform: uppercase;
        color: var(--otl-muted);
        margin-bottom: 10px;
    }
    .wcotl-search-row {
        display: flex;
        gap: 10px;
    }
    .wcotl-search input[type="text"] {
        flex: 1;
        border: 1.5px solid var(--otl-border);
        border-radius: 8px;
        padding: 12px 16px;
        font-size: 15px;
        letter-spacing: .08em;
        color: var(--otl-brand);
        background: #faf9f7;
        outline: none;
        transition: border-color .2s;
    }
    .wcotl-search input[type="text"]:focus {
        border-color: var(--otl-accent);
    }
    .wcotl-search button {
        background: var(--otl-brand);
        color: #fff;
        border: none;
        border-radius: 8px;
        padding: 12px 22px;
        font-size: 14px;
        letter-spacing: .08em;
        cursor: pointer;
        transition: background .2s, transform .1s;
        white-space: nowrap;
    }
    .wcotl-search button:hover { background: var(--otl-accent); transform: translateY(-1px); }

    /* Errore */
    .wcotl-error {
        background: #fff8f0;
        border: 1.5px solid #f5c07a;
        border-radius: 10px;
        padding: 16px 20px;
        color: #7a4a10;
        font-size: 14px;
        margin-bottom: 24px;
    }

    /* Badge codice */
    .wcotl-tracking-badge {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 32px;
    }
    .wcotl-tracking-badge__label {

        font-size: 11px;
        letter-spacing: .15em;
        text-transform: uppercase;
        color: var(--otl-muted);
    }
    .wcotl-tracking-badge__code {

        font-size: 15px;
        font-weight: bold;
        color: var(--otl-brand);
        background: #f0ede8;
        padding: 4px 12px;
        border-radius: 6px;
        letter-spacing: .12em;
    }

    /* Timeline */
    .wcotl-timeline {
        position: relative;
        padding-left: 0;
        list-style: none;
    }

    /* linea verticale */
    .wcotl-timeline::before {
        content: '';
        position: absolute;
        left: 23px;
        top: 10px;
        bottom: 10px;
        width: 2px;
        background: linear-gradient(to bottom, var(--otl-accent) 0%, var(--otl-line) 100%);
    }

    .wcotl-step {
        display: flex;
        gap: 20px;
        align-items: flex-start;
        padding-bottom: 32px;
        position: relative;
        animation: otl-fadein .4s ease both;
    }
    .wcotl-step:last-child { padding-bottom: 0; }

    @keyframes otl-fadein {
        from { opacity:0; transform: translateY(12px); }
        to   { opacity:1; transform: translateY(0); }
    }

    /* Pallino icona */
    .wcotl-step__dot {
        flex-shrink: 0;
        width: 46px;
        height: 46px;
        border-radius: 50%;
        background: var(--otl-surface);
        border: 2px solid var(--otl-line);
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--otl-muted);
        position: relative;
        z-index: 1;
        transition: border-color .3s, color .3s;
    }
    .wcotl-step--last .wcotl-step__dot {
        border-color: var(--otl-accent);
        color: var(--otl-accent);
        background: #fff8ee;
        box-shadow: 0 0 0 5px rgba(200,150,62,.12);
    }
    .wcotl-step__dot svg {
        width: 20px;
        height: 20px;
    }

    /* Card step */
    .wcotl-step__body {
        flex: 1;
        background: var(--otl-surface);
        border: 1px solid var(--otl-border);
        border-radius: 12px;
        padding: 18px 22px;
        box-shadow: 0 2px 12px rgba(0,0,0,.04);
        margin-top: 6px;
        position: relative;
    }
    .wcotl-step--last .wcotl-step__body {
        border-color: var(--otl-accent2);
        box-shadow: 0 2px 16px rgba(200,150,62,.12);
    }

    /* freccina sinistra della card */
    .wcotl-step__body::before {
        content: '';
        position: absolute;
        left: -8px;
        top: 16px;
        width: 14px;
        height: 14px;
        background: var(--otl-surface);
        border-left: 1px solid var(--otl-border);
        border-bottom: 1px solid var(--otl-border);
        transform: rotate(45deg);
    }
    .wcotl-step--last .wcotl-step__body::before {
        border-color: var(--otl-accent2);
    }

    .wcotl-step__date {

        font-size: 11px;
        letter-spacing: .12em;
        text-transform: uppercase;
        color: var(--otl-muted);
        margin-bottom: 6px;
    }
    .wcotl-step__label {
        font-size: 16px;
        font-weight: bold;
        color: var(--otl-brand);
        line-height: 1.3;
    }
    .wcotl-step--last .wcotl-step__label {
        color: var(--otl-accent);
    }
    .wcotl-step__note {
        margin-top: 8px;
        font-size: 14px;
        color: var(--otl-muted);
        line-height: 1.6;
        font-style: italic;
        border-top: 1px solid var(--otl-border);
        padding-top: 8px;
    }

    /* Estimated delivery banner */
    .wcotl-delivery {
        display: flex;
        align-items: center;
        gap: 16px;
        background: linear-gradient(135deg, #fff8ee 0%, #fff3e0 100%);
        border: 1.5px solid var(--otl-accent2);
        border-radius: var(--otl-radius);
        padding: 18px 24px;
        margin-bottom: 32px;
        box-shadow: 0 2px 12px rgba(200,150,62,.10);
    }
    .wcotl-delivery__icon {
        flex-shrink: 0;
        width: 42px;
        height: 42px;
        border-radius: 50%;
        background: var(--otl-accent);
        display: flex;
        align-items: center;
        justify-content: center;
        color: #fff;
    }
    .wcotl-delivery__icon svg { width: 20px; height: 20px; }
    .wcotl-delivery__label {
        font-size: 11px;
        letter-spacing: .14em;
        text-transform: uppercase;
        color: var(--otl-accent);
        margin-bottom: 4px;
    }
    .wcotl-delivery__date {
        font-size: 18px;
        font-weight: bold;
        color: var(--otl-brand);
        letter-spacing: -.2px;
    }

    /* Delivered banner (consegna effettuata) */
    .wcotl-delivered {
        display: flex;
        align-items: center;
        gap: 16px;
        background: linear-gradient(135deg, #eafaf1 0%, #d5f5e3 100%);
        border: 1.5px solid #a9dfbf;
        border-radius: var(--otl-radius);
        padding: 18px 24px;
        margin-bottom: 32px;
        box-shadow: 0 2px 12px rgba(39,174,96,.10);
    }
    .wcotl-delivered__icon {
        flex-shrink: 0;
        width: 42px;
        height: 42px;
        border-radius: 50%;
        background: #27ae60;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #fff;
    }
    .wcotl-delivered__icon svg { width: 20px; height: 20px; }
    .wcotl-delivered__label {
        font-size: 11px;
        letter-spacing: .14em;
        text-transform: uppercase;
        color: #1e8449;
        margin-bottom: 4px;
    }
    .wcotl-delivered__date {
        font-size: 18px;
        font-weight: bold;
        color: #145a32;
        letter-spacing: -.2px;
    }

    /* Step annullato (voided) */
    .wcotl-step--voided .wcotl-step__dot {
        border-color: #d0cbc4 !important;
        color: #b0aaa4 !important;
        background: #f5f3f0 !important;
        box-shadow: none !important;
        opacity: .55;
    }
    .wcotl-step--voided .wcotl-step__body {
        border-color: #e0dbd5 !important;
        box-shadow: none !important;
        background: #f9f7f5;
        opacity: .7;
    }
    .wcotl-step--voided .wcotl-step__body::before {
        border-color: #e0dbd5 !important;
        background: #f9f7f5;
    }
    .wcotl-step--voided .wcotl-step__label {
        text-decoration: line-through;
        color: #a09a94 !important;
    }
    .wcotl-step--voided .wcotl-step__date {
        text-decoration: line-through;
        color: #bbb5ae;
    }
    .wcotl-step--voided .wcotl-step__note {
        color: #b0aaa4;
    }
    .wcotl-step__void-badge {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        margin-top: 8px;
        font-size: 11px;
        letter-spacing: .10em;
        text-transform: uppercase;
        color: #c0392b;
        background: #fdf2f0;
        border: 1px solid #f5b7b1;
        border-radius: 5px;
        padding: 3px 8px;
        font-weight: 600;
    }
    .wcotl-step__void-reason {
        margin-top: 6px;
        font-size: 13px;
        color: #b05050;
        font-style: italic;
        border-top: 1px dashed #f5b7b1;
        padding-top: 6px;
    }

    /* Responsive */
    @media (max-width: 520px) {
        .wcotl-search { padding: 20px 16px; }
        .wcotl-search-row { flex-direction: column; }
        .wcotl-step__body { padding: 14px 14px; }
        .wcotl-delivery { padding: 14px 16px; gap: 12px; }
        .wcotl-delivery__date { font-size: 16px; }
        .wcotl-delivered { padding: 14px 16px; gap: 12px; }
        .wcotl-delivered__date { font-size: 16px; }
    }
    </style>

    <div class="wcotl-wrap">
        <div class="wcotl-container">

            <div class="wcotl-header">
                <span class="wcotl-header__eyebrow">Shipment &amp; Delivery</span>
                <h1>Track your order</h1>
                <p>Insert the tracking code recived via email.</p>
            </div>

            <div class="wcotl-search">
                <label for="wcotl-input">Tracking code</label>
                <form method="GET" action="">
                    <?php
                    // Preserva gli altri parametri GET (es. page id in WP)
                    foreach ( $_GET as $k => $v ) {
                        if ( $k === 'tracking' ) continue;
                        echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
                    }
                    ?>
                    <div class="wcotl-search-row">
                        <input
                            type="text"
                            id="wcotl-input"
                            name="tracking"
                            placeholder="es. TRK-20240518-001"
                            value="<?php echo esc_attr( $code ); ?>"
                            autocomplete="off"
                            spellcheck="false"
                        >
                        <button type="submit">Search →</button>
                    </div>
                </form>
            </div>

            <?php if ( $error ) : ?>
                <div class="wcotl-error"><?php echo wp_kses_post( $error ); ?></div>
            <?php endif; ?>

            <?php if ( ! empty( $steps ) ) : ?>
                <div class="wcotl-tracking-badge">
                    <span class="wcotl-tracking-badge__label">Code</span>
                    <span class="wcotl-tracking-badge__code"><?php echo esc_html( $code ); ?></span>
                </div>

                <?php if ( ! empty( $delivered_at ) ) :
                    $dd = DateTime::createFromFormat( 'Y-m-d', $delivered_at );
                    $dd_label = $dd ? $dd->format('d/m/Y') : esc_html( $delivered_at );
                ?>
                <div class="wcotl-delivered">
                    <div class="wcotl-delivered__icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    </div>
                    <div>
                        <div class="wcotl-delivered__label">Delivered</div>
                        <div class="wcotl-delivered__date"><?php echo esc_html( $dd_label ); ?></div>
                    </div>
                </div>
                <?php elseif ( ! empty( $estimated_delivery ) ) :
                    $ed = DateTime::createFromFormat( 'Y-m-d', $estimated_delivery );
                    $ed_label = $ed ? $ed->format('d/m/Y') : esc_html( $estimated_delivery );
                ?>
                <div class="wcotl-delivery">
                    <div class="wcotl-delivery__icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    </div>
                    <div>
                        <div class="wcotl-delivery__label">Estimated delivery</div>
                        <div class="wcotl-delivery__date"><?php echo esc_html( $ed_label ); ?></div>
                    </div>
                </div>
                <?php endif; ?>

                <ul class="wcotl-timeline">
                    <?php foreach ( $steps as $i => $step ) :
                        $icon_key  = sanitize_key( $step->step_icon ?: 'truck' );
                        $icon_svg  = isset( $icons[ $icon_key ] ) ? $icons[ $icon_key ] : $icons['truck'];
                        $delay     = $i * 80; // ms
                        $dt        = new DateTime( $step->step_date );
                        $date_fmt  = $dt->format( 'd/m/Y' );
                        $time_fmt  = $dt->format( 'H:i' );
                        $is_voided = ! empty( $step->step_voided );
                        // "last active" = l'ultimo step NON annullato
                        $last_active = false;
                        foreach ( array_reverse( $steps ) as $ls ) {
                            if ( empty( $ls->step_voided ) ) { $last_active = ( $ls->id === $step->id ); break; }
                        }
                        $step_class = 'wcotl-step' . ( $is_voided ? ' wcotl-step--voided' : '' ) . ( $last_active ? ' wcotl-step--last' : '' );
                    ?>
                    <li class="<?php echo esc_attr( $step_class ); ?>" style="animation-delay:<?php echo $delay; ?>ms">
                        <div class="wcotl-step__dot">
                            <?php if ( $is_voided ) : ?>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                            <?php else : ?>
                            <?php echo $icon_svg; ?>
                            <?php endif; ?>
                        </div>
                        <div class="wcotl-step__body">
                            <div class="wcotl-step__date"><?php echo esc_html( $date_fmt ); ?> &nbsp;·&nbsp; <?php echo esc_html( $time_fmt ); ?></div>
                            <div class="wcotl-step__label"><?php echo esc_html( $step->step_label ); ?></div>
                            <?php if ( ! empty( $step->step_note ) ) : ?>
                                <div class="wcotl-step__note"><?php echo nl2br( esc_html( $step->step_note ) ); ?></div>
                            <?php endif; ?>
                            <?php if ( $is_voided ) : ?>
                                <div class="wcotl-step__void-badge">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="width:12px;height:12px;"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
                                    Unconfirmed information
                                </div>
                                <?php if ( ! empty( $step->step_void_reason ) ) : ?>
                                    <div class="wcotl-step__void-reason"><?php echo nl2br( esc_html( $step->step_void_reason ) ); ?></div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

        </div>
    </div>
    <?php
    return ob_get_clean();
}

}

