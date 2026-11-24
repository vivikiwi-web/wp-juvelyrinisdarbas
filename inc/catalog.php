<?php
defined( 'ABSPATH' ) || exit;

function jd_is_catalog_product( $product = null ) {
    if ( null === $product ) {
        $product = wc_get_product( get_the_ID() );
    } elseif ( ! $product instanceof WC_Product ) {
        $product = is_numeric( $product ) && $product > 0 ? wc_get_product( (int) $product ) : false;
    }
    return $product && (bool) $product->get_meta( '_jd_catalog_id' );
}

function jd_catalog_spec_fields() {
    return array(
        'material' => 'Medžiaga', 'fineness' => 'Praba', 'gold_color' => 'Aukso spalva',
        'weight_g' => 'Gaminio svoris (g)', 'ring_size_mm' => 'Vidinis žiedo skersmuo (mm)',
        'stone_type' => 'Akmuo', 'stone_weight_ct' => 'Akmens svoris (ct)',
        'stone_color' => 'Akmens spalva', 'stone_clarity' => 'Akmens švarumas',
        'certificate' => 'Sertifikatas',
    );
}

/** Build ordinary, visible WooCommerce product attributes from imported facts. */
function jd_catalog_attributes( $specs ) {
    $attributes = array();
    foreach ( jd_catalog_spec_fields() as $key => $label ) {
        $value = $specs[ $key ] ?? '';
        if ( null === $value || '' === trim( (string) $value ) ) {
            continue;
        }
        $attribute = new WC_Product_Attribute();
        $attribute->set_name( $label );
        $attribute->set_options( array( (string) $value ) );
        $attribute->set_visible( true );
        $attribute->set_variation( false );
        $attribute->set_position( count( $attributes ) );
        $attributes[ sanitize_title( $label ) ] = $attribute;
    }
    return $attributes;
}

add_filter( 'woocommerce_product_data_tabs', function ( $tabs ) {
    $tabs['jd_catalog'] = array( 'label' => 'Katalogas ir užklausa', 'target' => 'jd-catalog-data', 'priority' => 65, 'class' => array( 'show_if_simple' ) );
    return $tabs;
} );
add_action( 'woocommerce_product_data_panels', function () {
    global $product_object;
    echo '<div id="jd-catalog-data" class="panel woocommerce_options_panel hidden"><div class="options_group">';
    if ( ! jd_is_catalog_product( $product_object ) ) {
        echo '<p class="form-field">Ši skiltis skirta naujojo katalogo modeliams.</p></div></div>';
        return;
    }
    woocommerce_wp_checkbox( array( 'id' => '_jd_custom_request', 'label' => 'Leisti pageidavimus', 'description' => 'Klientas gali pateikti pageidavimą kitokiam variantui.' ) );
    echo '</div>';
    jd_render_preference_editor( $product_object );
    echo '</div>';
} );
add_action( 'woocommerce_admin_process_product_object', function ( $product ) {
    if ( jd_is_catalog_product( $product ) && isset( $_POST['woocommerce_meta_nonce'] ) ) {
        $product->update_meta_data( '_jd_custom_request', isset( $_POST['_jd_custom_request'] ) ? 'yes' : 'no' );
        if ( isset( $_POST['jd_fields_present'] ) ) {
            $fields = jd_sanitize_preference_fields( wp_unslash( $_POST['jd_fields'] ?? array() ) );
            if ( is_wp_error( $fields ) ) { WC_Admin_Meta_Boxes::add_error( $fields->get_error_message() ); }
            else { $product->update_meta_data( '_jd_preference_fields', $fields ); }
        }
    }
} );

add_filter( 'woocommerce_is_purchasable', function ( $purchasable, $product ) {
    return jd_is_catalog_product( $product ) ? false : $purchasable;
}, 10, 2 );
add_filter( 'woocommerce_get_price_html', function ( $html, $product ) {
    if ( ! jd_is_catalog_product( $product ) ) {
        return $html;
    }
    return '' === $product->get_price() ? '' : wc_price( $product->get_price(), array( 'currency' => 'EUR' ) );
}, 10, 2 );

function jd_catalog_stock_badge() {
    global $product;
    if ( ! jd_is_catalog_product( $product ) ) { return; }
    $stock = $product->get_stock_status();
    if ( ! in_array( $stock, array( 'instock', 'outofstock' ), true ) ) { return; }
    $single = doing_action( 'woocommerce_single_product_summary' );
    $label = 'instock' === $stock ? ( $single ? 'Turime vietoje' : 'Turime' ) : 'Užsakoma';
    echo '<span class="jd-stock-badge jd-stock-badge--' . esc_attr( $stock ) . '">' . esc_html( $label ) . '</span>';
}
add_action( 'woocommerce_before_shop_loop_item_title', 'jd_catalog_stock_badge', 12 );
add_action( 'woocommerce_single_product_summary', 'jd_catalog_stock_badge', 21 );
add_filter( 'woocommerce_post_class', function ( $classes, $product ) {
    if ( jd_is_catalog_product( $product ) ) { $classes[] = 'jd-catalog-product'; }
    return $classes;
}, 10, 2 );

add_action( 'woocommerce_single_product_summary', function () {
    global $product;
    if ( ! jd_is_catalog_product( $product ) ) {
        return;
    }
    echo '<p class="jd-model-code">Modelis: ' . esc_html( $product->get_sku() ) . '</p>';
    echo '<button type="button" class="button jd-inquiry-add" data-product-id="' . esc_attr( $product->get_id() ) . '">Pateikti užklausą</button><p class="jd-inquiry-inline-status" role="status"></p>';
    if ( 'yes' === $product->get_meta( '_jd_custom_request' ) ) {
        echo '<button type="button" class="button jd-preferences-open" aria-haspopup="dialog" aria-controls="jd-preferences-dialog">Užsakyti individualiai</button>';
    }
}, 31 );

add_action( 'woocommerce_after_single_product_summary', function () {
    global $product;
    if ( ! jd_is_catalog_product( $product ) || 'yes' !== $product->get_meta( '_jd_custom_request' ) ) {
        return;
    }
    echo '<dialog id="jd-preferences-dialog" class="jd-custom-request" aria-labelledby="jd-preferences-title"><button type="button" class="jd-preferences-close" aria-label="Uždaryti">×</button><h2 id="jd-preferences-title">Jūsų pageidavimai</h2><p>Aprašykite savo pageidavimus. Galimybes, tikslius parametrus ir kainą patikslinsime atsakydami į užklausą.</p><form class="jd-preferences" data-product-id="' . esc_attr( $product->get_id() ) . '"><div class="jd-preferences-grid">';
    jd_render_preference_inputs( $product );
    echo '</div><button type="submit" class="button">Tęsti užklausą</button><p role="status" class="jd-inquiry-inline-status"></p></form></dialog>';
}, 8 );
