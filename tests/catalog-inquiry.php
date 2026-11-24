<?php
/** Run only locally: wp --user=<administrator> eval-file tests/catalog-inquiry.php */
if ( 'juvelyrinisdarbas.local' !== wp_parse_url( home_url(), PHP_URL_HOST ) || ! current_user_can( 'manage_woocommerce' ) ) {
    WP_CLI::error( 'Tests require the local site and an administrator.' );
}
$assert = function ( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } };
$ring = wc_get_product( wc_get_product_id_by_sku( 'JD-0033' ) );
$earrings = wc_get_product( wc_get_product_id_by_sku( 'JD-0052' ) );
$assert( $ring && $earrings, 'Import the five test drafts first.' );
$assert( 'simple' === $ring->get_type(), 'Only simple products expected.' );
$assert( ! $ring->is_purchasable(), 'Model must not be purchasable through checkout.' );
$assert( '' === $ring->get_meta( '_jd_in_salon' ), 'Legacy stock flag must be removed.' );
$assert( false !== strpos( html_entity_decode( wp_strip_all_tags( $ring->get_price_html() ) ), '€' ), 'Price should be displayed in EUR.' );
$invalid = array(
    array( array( 'product_id' => 2147483647 ) ),
    array( array( 'product_id' => $earrings->get_id(), 'mode' => 'custom', 'preferences' => array( 'size' => '16' ) ) ),
    array( array( 'product_id' => $ring->get_id(), 'mode' => 'custom', 'preferences' => array( 'material' => 'silver', 'gold_color' => 'white' ) ) ),
    array( array( 'product_id' => $ring->get_id(), 'mode' => 'custom', 'preferences' => array( 'carat' => '-1' ) ) ),
    array( array( 'product_id' => $ring->get_id(), 'mode' => 'custom', 'preferences' => array( 'stock' => 'in_stock' ) ) ),
);
foreach ( $invalid as $input ) { $assert( is_wp_error( jd_validate_inquiry_items( $input ) ), 'Invalid selection was accepted.' ); }
$draft = new WC_Product_Simple();
$draft->set_name( 'Vietinis prieigos testas' );
$draft->set_status( 'draft' );
$draft->update_meta_data( '_jd_catalog_id', wp_generate_uuid4() );
$draft->save();
$user_id = get_current_user_id();
wp_set_current_user( 0 );
$assert( is_wp_error( jd_validate_inquiry_items( array( array( 'product_id' => $draft->get_id() ) ) ) ), 'Anonymous user can access draft data.' );
$draft->delete( true );
wp_set_current_user( $user_id );

$items = array( array( 'product_id' => $ring->get_id(), 'mode' => 'custom', 'title' => 'Forged title', 'price' => '0.01',
    'preferences' => array( 'size' => '16.5', 'material' => 'gold', 'gold_color' => 'white', 'stone' => 'Safyras' ) ) );
$stock_before = $ring->get_stock_quantity();
$contact = array( 'name' => 'Vietinis sistemos testas', 'phone' => '+37060000000', 'email' => 'catalog-test@example.invalid', 'comment' => 'Temporary test; no email is sent.' );
$token = wp_generate_uuid4();
$order = null;
try {
    $order = jd_create_inquiry_order( $items, $contact, $token );
    $assert( $order instanceof WC_Order, 'Request order could not be created.' );
    $assert( 'jd-inquiry' === $order->get_status(), 'Wrong order status.' );
    $assert( ! $order->needs_payment() && null === $order->get_date_paid(), 'Request became payable/paid.' );
    $assert( false === apply_filters( 'woocommerce_can_reduce_order_stock', true, $order ), 'Stock reduction not blocked.' );
    $again = jd_create_inquiry_order( $items, $contact, $token );
    $assert( $again instanceof WC_Order && $again->get_id() === $order->get_id(), 'Retry created a duplicate.' );
    $changed = $contact; $changed['comment'] = 'Changed payload';
    $assert( is_wp_error( jd_create_inquiry_order( $items, $changed, $token ) ), 'Changed replay was accepted.' );
    $line = current( $order->get_items() );
    $assert( $line->get_name() === $ring->get_name(), 'Client title was trusted.' );
    $assert( '0' === (string) $line->get_total(), 'Request unexpectedly became an invoice.' );
    $assert( $stock_before === wc_get_product( $ring->get_id() )->get_stock_quantity(), 'Stock changed.' );
} finally {
    if ( $order instanceof WC_Order ) { $order->delete( true ); }
    wp_set_current_user( $user_id );
}
WP_CLI::success( 'Validation, draft access, idempotency, canonical item data, no payment/stock effects passed. Disposable test order removed; no email sent.' );

$probe = new WC_Product_Simple();
try {
    $probe->set_name( 'Vietinis laikinas testas' );
    $probe->set_status( 'draft' );
    $probe->update_meta_data( '_jd_catalog_id', wp_generate_uuid4() );
    $probe->update_meta_data( '_jd_example_price', 'yes' );
    $probe->set_stock_status( 'instock' );
    $probe->set_attributes( jd_catalog_attributes( array( 'material' => 'Auksas', 'fineness' => '' ) ) );
    $probe->save();
    wp_update_post( array( 'ID' => $probe->get_id(), 'post_status' => 'publish' ) );
    $assert( 'publish' === get_post_status( $probe->get_id() ), 'Publication was blocked.' );
    $assert( count( wc_get_product( $probe->get_id() )->get_attributes() ) === 1, 'Native attributes not saved.' );
    $assert( $probe->is_in_stock(), 'Native stock not used.' );
    $_POST['woocommerce_meta_nonce'] = 'local-test';
    do_action( 'woocommerce_admin_process_product_object', $probe );
    $assert( 'instock' === $probe->get_stock_status(), 'Admin save overwrote native stock.' );
    $probe->set_stock_status( 'outofstock' );
    $assert( ! $probe->is_in_stock(), 'Native out-of-stock status ignored.' );
} finally {
    unset( $_POST['woocommerce_meta_nonce'] );
    if ( $probe->get_id() ) { $probe->delete( true ); }
}
WP_CLI::success( 'Native attributes, stock and unrestricted publication verified; temporary product removed.' );
