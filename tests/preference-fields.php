<?php
/** Local integration tests; temporary product/order only, no mail. */
if ( 'juvelyrinisdarbas.local' !== wp_parse_url( home_url(), PHP_URL_HOST ) || ! current_user_can( 'manage_woocommerce' ) ) { WP_CLI::error( 'Local administrator required.' ); }
$assert = function ( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } };
$product = new WC_Product_Simple();
$order = null;
try {
    $product->set_name( 'Vietinis laukų testas' );
    $product->set_status( 'draft' );
    $product->update_meta_data( '_jd_catalog_id', wp_generate_uuid4() );
    $product->update_meta_data( '_jd_custom_request', 'yes' );
    $product->save();
    $rows = array(
        array( 'key' => 'engraving', 'enabled' => 1, 'label' => 'Graviravimas', 'type' => 'text' ),
        array( 'key' => 'finish', 'enabled' => 1, 'label' => 'Paviršius', 'type' => 'select', 'choices' => "matte | Matinis\nPoliruotas" ),
        array( 'key' => 'length', 'enabled' => 1, 'label' => 'Ilgis (mm)', 'type' => 'number', 'min' => '1', 'max' => '10', 'step' => '0.5' ),
        array( 'key' => 'stone', 'label' => 'Akmuo', 'type' => 'text' ),
    );
    $fields = jd_sanitize_preference_fields( $rows );
    $assert( ! is_wp_error( $fields ), 'Valid editor fields rejected.' );
    $_POST['woocommerce_meta_nonce'] = 'local-test';
    $_POST['jd_fields_present'] = 1;
    $_POST['jd_fields'] = $rows;
    $_POST['_jd_custom_request'] = 1;
    do_action( 'woocommerce_admin_process_product_object', $product );
    $product->save();
    $product = wc_get_product( $product->get_id() );
    $assert( $product->get_meta( '_jd_preference_fields' ) === $fields, 'Editor save hook did not persist schema.' );
    $assert( count( jd_preference_fields( $product ) ) === 3, 'Disabled field still active.' );
    $valid = array( array( 'product_id' => $product->get_id(), 'mode' => 'custom', 'preferences' => array( 'engraving' => 'Testas', 'finish' => 'matte', 'length' => '2.5' ) ) );
    $assert( ! is_wp_error( jd_validate_inquiry_items( $valid ) ), 'Custom fields rejected.' );
    foreach ( array( array( 'stone' => 'Safyras' ), array( 'finish' => 'forged' ), array( 'length' => '11' ), array( 'length' => '2.2' ), array( 'unknown' => 'value' ), array( 'engraving' => array( 'bad' ) ) ) as $prefs ) {
        $bad = $valid; $bad[0]['preferences'] = $prefs;
        $assert( is_wp_error( jd_validate_inquiry_items( $bad ) ), 'Invalid, disabled or stale preference accepted.' );
    }
    ob_start(); jd_render_preference_inputs( $product ); $html = ob_get_clean();
    $assert( false !== strpos( $html, 'Graviravimas' ) && false === strpos( $html, 'name="stone"' ), 'Popup ignored current schema.' );
    $order = jd_create_inquiry_order( $valid, array( 'name' => 'Vietinis testas', 'phone' => '+37060000000', 'email' => 'fields-test@example.invalid' ), wp_generate_uuid4() );
    $assert( $order instanceof WC_Order, 'Custom request order failed.' );
    $line = current( $order->get_items() );
    $assert( 'Matinis' === $line->get_meta( 'Paviršius' ) && 'Testas' === $line->get_meta( 'Graviravimas' ), 'Order did not use canonical labels/options.' );
    $assert( ! $order->needs_payment() && false === apply_filters( 'woocommerce_can_reduce_order_stock', true, $order ), 'Payment or stock protection failed.' );
    // Recreated fields use arbitrary keys, with no dependency on material/gold_color names.
    $conditional_rows = array(
        array( 'key' => 'metal_choice', 'enabled' => 1, 'label' => 'Metalas', 'type' => 'select', 'choices' => "a | Auksas\ns | Sidabras" ),
        array( 'key' => 'new_color', 'enabled' => 1, 'label' => 'Spalva', 'type' => 'select', 'choices' => 'w | Baltas', 'condition_field' => 'metal_choice', 'condition_operator' => 'not_equals', 'condition_value' => 's' ),
        array( 'key' => 'engraving', 'enabled' => 1, 'label' => 'Graviravimas', 'type' => 'text', 'condition_field' => 'new_color', 'condition_operator' => 'equals', 'condition_value' => 'w' ),
    );
    $conditional = jd_sanitize_preference_fields( $conditional_rows );
    $assert( ! is_wp_error( $conditional ), 'Valid conditions rejected.' );
    ob_start(); jd_render_preference_editor_row( 'new_color', $conditional['new_color'], 1, $conditional ); $editor_html = ob_get_clean();
    $document = new DOMDocument();
    $previous_errors = libxml_use_internal_errors( true );
    $document->loadHTML( '<?xml encoding="UTF-8">' . $editor_html );
    libxml_clear_errors(); libxml_use_internal_errors( $previous_errors );
    $xpath = new DOMXPath( $document );
    foreach ( array( 'condition_field' => 'metal_choice', 'condition_operator' => 'not_equals', 'condition_value' => 's' ) as $part => $expected ) {
        $options = $xpath->query( '//select[@name="jd_fields[1][' . $part . ']"]/option[@selected]' );
        $assert( 1 === $options->length && $expected === $options->item( 0 )->getAttribute( 'value' ), 'Editor did not show stored condition: ' . $part );
    }

    $product->update_meta_data( '_jd_preference_fields', $conditional ); $product->save();
    $selection = array( array( 'product_id' => $product->get_id(), 'mode' => 'custom', 'preferences' => array( 'metal_choice' => 'a', 'new_color' => 'w', 'engraving' => 'Testas' ) ) );
    $assert( ! is_wp_error( jd_validate_inquiry_items( $selection ) ), 'Active conditional fields rejected.' );
    $selection[0]['preferences']['metal_choice'] = 's';
    $assert( is_wp_error( jd_validate_inquiry_items( $selection ) ), 'Inactive dependent field accepted.' );
    unset( $selection[0]['preferences']['new_color'] );
    $assert( is_wp_error( jd_validate_inquiry_items( $selection ) ), 'Inactive chained field accepted.' );
    unset( $selection[0]['preferences']['engraving'] );
    $assert( ! is_wp_error( jd_validate_inquiry_items( $selection ) ), 'Silver request without color rejected.' );
    $conditional['metal_choice']['enabled'] = false;
    $assert( ! jd_preference_field_active( 'new_color', $conditional, array() ), 'Disabled source activated dependent field.' );
    $bad = $conditional_rows; $bad[1]['condition_value'] = 'missing';
    $assert( is_wp_error( jd_sanitize_preference_fields( $bad ) ), 'Removed condition option accepted.' );
    $bad = $conditional_rows; $bad[1]['condition_field'] = 'deleted';
    $assert( is_wp_error( jd_sanitize_preference_fields( $bad ) ), 'Deleted source accepted.' );
    $bad = $conditional_rows; $bad[0]['condition_field'] = 'new_color'; $bad[0]['condition_value'] = 'w';
    $assert( is_wp_error( jd_sanitize_preference_fields( $bad ) ), 'Cyclic conditions accepted.' );
    $bad = $conditional_rows; $bad[1]['condition_field'] = 'new_color'; $bad[1]['condition_value'] = 'w';
    $assert( is_wp_error( jd_sanitize_preference_fields( $bad ) ), 'Self-reference accepted.' );
    $legacy = jd_default_preference_fields( $product );
    foreach ( $legacy as &$legacy_field ) { unset( $legacy_field['condition'] ); } unset( $legacy_field );
    $product->update_meta_data( '_jd_preference_fields', $legacy ); $product->save();
    $assert( 'silver' === jd_preference_fields( $product )['gold_color']['condition']['value'], 'Legacy relationship not retained.' );
    $legacy['gold_color']['condition'] = null;
    $product->update_meta_data( '_jd_preference_fields', $legacy ); $product->save();
    $assert( null === jd_preference_fields( $product )['gold_color']['condition'], 'Explicit always-active choice was overridden.' );
    $fields['finish']['options'] = array( 'new' => 'Naujas' );
    $product->update_meta_data( '_jd_preference_fields', $fields ); $product->save();
    $assert( is_wp_error( jd_validate_inquiry_items( $valid ) ), 'Stale choices accepted.' );
    $bad = $rows; $bad[1]['choices'] = "same\nsame";
    $assert( is_wp_error( jd_sanitize_preference_fields( $bad ) ), 'Duplicate choices accepted.' );
    $bad = $rows; $bad[2]['step'] = '0';
    $assert( is_wp_error( jd_sanitize_preference_fields( $bad ) ), 'Invalid step accepted.' );
} finally {
    unset( $_POST['woocommerce_meta_nonce'], $_POST['jd_fields_present'], $_POST['jd_fields'], $_POST['_jd_custom_request'] );
    if ( $order instanceof WC_Order ) { $order->delete( true ); }
    if ( $product->get_id() ) { $product->delete( true ); }
}
WP_CLI::success( 'Editor save, custom fields, disabled fields, choices/ranges/step, conditions/chains/cycles/legacy configuration, stale schema and canonical order metadata passed. Temporary data removed; no mail sent.' );
