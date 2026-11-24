<?php
defined( 'ABSPATH' ) || exit;

function jd_inquiry_form_id() {
    return (int) get_option( 'jd_inquiry_form_id', 0 );
}

function jd_validate_inquiry_items( $items ) {
    if ( ! is_array( $items ) || ! $items || count( $items ) > 10 ) {
        return new WP_Error( 'items', 'Pridėkite nuo vieno iki dešimties gaminių.' );
    }
    $clean = array();
    foreach ( $items as $item ) {
        if ( ! is_array( $item ) || empty( $item['product_id'] ) || ! is_numeric( $item['product_id'] ) ) {
            return new WP_Error( 'item', 'Netinkamas gaminys.' );
        }
        $product = wc_get_product( absint( $item['product_id'] ) );
        if ( ! jd_is_catalog_product( $product ) || ( 'publish' !== $product->get_status() && ! current_user_can( 'edit_post', $product->get_id() ) ) ) {
            return new WP_Error( 'product', 'Šis gaminys nepasiekiamas. Atnaujinkite užklausą.' );
        }
        $mode = isset( $item['mode'] ) ? $item['mode'] : 'model';
        if ( ! in_array( $mode, array( 'model', 'custom' ), true ) || ( 'custom' === $mode && 'yes' !== $product->get_meta( '_jd_custom_request' ) ) ) {
            return new WP_Error( 'mode', 'Šiam modeliui negalima pateikti tokių pageidavimų.' );
        }
        $prefs = isset( $item['preferences'] ) ? $item['preferences'] : array();
        if ( ! is_array( $prefs ) || ( 'model' === $mode && $prefs ) ) {
            return new WP_Error( 'preferences', 'Netinkami pageidavimai.' );
        }
        $fields = jd_preference_fields( $product );
        $allowed = array_keys( $fields );
        if ( array_diff( array_keys( $prefs ), $allowed ) ) {
            return new WP_Error( 'fields', 'Užklausoje yra neleistinų laukų.' );
        }
        $values = array();
        foreach ( $prefs as $key => $value ) {
            if ( ! is_scalar( $value ) || strlen( (string) $value ) > ( 'textarea' === $fields[ $key ]['type'] ? 6000 : 480 ) ) {
                return new WP_Error( 'length', 'Pageidavimo tekstas per ilgas.' );
            }
            $value = trim( sanitize_textarea_field( (string) $value ) );
            if ( '' === $value ) {
                continue;
            }
            $field = $fields[ $key ];
            if ( mb_strlen( $value ) > ( 'textarea' === $field['type'] ? 1500 : 120 ) ) {
                return new WP_Error( 'length', 'Pageidavimo tekstas per ilgas.' );
            }
            if ( 'number' === $field['type'] ) {
                $value = str_replace( ',', '.', $value );
                if ( ! is_numeric( $value ) || ! is_finite( (float) $value ) ||
                    ( '' !== $field['min'] && (float) $value < (float) $field['min'] ) ||
                    ( '' !== $field['max'] && (float) $value > (float) $field['max'] ) ) {
                    return new WP_Error( 'number', 'Patikrinkite lauką „' . $field['label'] . '“.' );
                }
                if ( 'any' !== $field['step'] && '' !== $field['step'] ) {
                    $steps = ( (float) $value - (float) ( $field['min'] ?: 0 ) ) / (float) $field['step'];
                    if ( abs( $steps - round( $steps ) ) > 0.000001 ) { return new WP_Error( 'step', 'Patikrinkite lauką „' . $field['label'] . '“.' ); }
                }
            }
            if ( 'select' === $field['type'] && ! array_key_exists( $value, $field['options'] ) ) {
                return new WP_Error( 'choice', 'Pasirinkimas lauke „' . $field['label'] . '“ pasikeitė. Atnaujinkite užklausą.' );
            }
            $values[ $key ] = $value;
        }
        foreach ( $values as $key => $value ) {
            if ( ! jd_preference_field_active( $key, $fields, $values ) ) {
                return new WP_Error( 'condition', 'Laukas „' . $fields[ $key ]['label'] . '“ šiam pasirinkimui netaikomas. Atnaujinkite pageidavimus.' );
            }
        }
        $clean[] = array( 'product_id' => $product->get_id(), 'mode' => $mode, 'preferences' => $values );
    }
    return $clean;
}

function jd_inquiry_item_data( $product ) {
    return array( 'product_id' => $product->get_id(), 'title' => $product->get_name(), 'sku' => $product->get_sku(),
        'image' => wp_get_attachment_image_url( $product->get_image_id(), 'woocommerce_gallery_thumbnail' ) ?: wc_placeholder_img_src(),
        'in_salon' => 'instock' === $product->get_stock_status(), 'fields' => jd_preference_fields( $product ) );
}
add_action( 'wp_ajax_jd_catalog_item', 'jd_ajax_catalog_item' );
add_action( 'wp_ajax_nopriv_jd_catalog_item', 'jd_ajax_catalog_item' );
function jd_ajax_catalog_item() {
    check_ajax_referer( 'jd_catalog', 'nonce' );
    $items = jd_validate_inquiry_items( array( array( 'product_id' => absint( $_POST['product_id'] ?? 0 ), 'mode' => 'model' ) ) );
    if ( is_wp_error( $items ) ) {
        wp_send_json_error( array( 'message' => $items->get_error_message() ), 400 );
    }
    wp_send_json_success( jd_inquiry_item_data( wc_get_product( $items[0]['product_id'] ) ) );
}

add_action( 'init', function () {
    register_post_status( 'wc-jd-inquiry', array( 'label' => 'Užklausa', 'public' => false, 'exclude_from_search' => true,
        'show_in_admin_all_list' => true, 'show_in_admin_status_list' => true,
        'label_count' => _n_noop( 'Užklausa (%s)', 'Užklausos (%s)', 'nm-framework' ) ) );
} );
add_filter( 'wc_order_statuses', function ( $statuses ) {
    $statuses['wc-jd-inquiry'] = 'Užklausa';
    return $statuses;
} );
add_filter( 'woocommerce_order_needs_payment', function ( $needed, $order ) {
    return $order->get_meta( '_jd_inquiry' ) ? false : $needed;
}, 10, 2 );
add_filter( 'woocommerce_can_reduce_order_stock', function ( $allowed, $order ) {
    return $order && $order->get_meta( '_jd_inquiry' ) ? false : $allowed;
}, 10, 2 );

function jd_create_inquiry_order( $items, $contact, $token ) {
    $items = jd_validate_inquiry_items( $items );
    if ( is_wp_error( $items ) ) {
        return $items;
    }
    if ( ! preg_match( '/^[a-zA-Z0-9-]{20,80}$/', $token ) || ! is_email( $contact['email'] ?? '' ) ||
        empty( $contact['name'] ) || empty( $contact['phone'] ) || strlen( $contact['name'] ) > 200 ||
        strlen( $contact['phone'] ) > 60 || strlen( $contact['comment'] ?? '' ) > 8000 ) {
        return new WP_Error( 'contact', 'Patikrinkite kontaktinius duomenis ir bandykite dar kartą.' );
    }
    $key = hash_hmac( 'sha256', $token . strtolower( $contact['email'] ), wp_salt( 'nonce' ) );
    $fingerprint = hash( 'sha256', wp_json_encode( array( $items, $contact ) ) );
    $existing = wc_get_orders( array( 'limit' => 1, 'type' => 'shop_order', 'meta_query' => array( array( 'key' => '_jd_request_key', 'value' => $key ) ) ) );
    if ( $existing ) {
        return hash_equals( (string) $existing[0]->get_meta( '_jd_request_fingerprint' ), $fingerprint ) ? $existing[0] : new WP_Error( 'changed', 'Užklausos turinys pasikeitė. Pradėkite naują užklausą.' );
    }
    $lock = 'jd_inquiry_lock_' . $key;
    $locked_at = (int) get_option( $lock, 0 );
    if ( $locked_at && $locked_at < time() - 300 ) {
        delete_option( $lock );
    }
    if ( ! add_option( $lock, time(), '', false ) ) {
        return new WP_Error( 'busy', 'Užklausa jau apdorojama. Pabandykite po kelių sekundžių.' );
    }
    $order = null;
    try {
        // Another request may have completed between the first lookup and lock.
        $existing = wc_get_orders( array( 'limit' => 1, 'type' => 'shop_order', 'meta_query' => array( array( 'key' => '_jd_request_key', 'value' => $key ) ) ) );
        if ( $existing ) {
            return hash_equals( (string) $existing[0]->get_meta( '_jd_request_fingerprint' ), $fingerprint ) ? $existing[0] : new WP_Error( 'changed', 'Užklausos turinys pasikeitė. Pradėkite naują užklausą.' );
        }
        $order = new WC_Order();
        $order->set_status( 'jd-inquiry' );
        $order->set_created_via( 'jd-inquiry' );
        $order->set_currency( 'EUR' );
        $order->set_billing_first_name( sanitize_text_field( $contact['name'] ) );
        $order->set_billing_phone( sanitize_text_field( $contact['phone'] ) );
        $order->set_billing_email( sanitize_email( $contact['email'] ) );
        $order->set_customer_note( sanitize_textarea_field( $contact['comment'] ?? '' ) );
        $order->update_meta_data( '_jd_inquiry', 'yes' );
        $order->update_meta_data( '_jd_request_key', $key );
        $order->update_meta_data( '_jd_request_fingerprint', $fingerprint );
        $order->update_meta_data( '_jd_mail_state', 'pending' );
        foreach ( $items as $selection ) {
            $product = wc_get_product( $selection['product_id'] );
            $line = new WC_Order_Item_Product();
            $line->set_product( $product );
            $line->set_quantity( 1 );
            // Request records are not invoices: unknown/quoted prices are metadata.
            $line->set_subtotal( 0 );
            $line->set_total( 0 );
            $line->add_meta_data( 'Modelio kodas', $product->get_sku() );
            $line->add_meta_data( 'Užklausos tipas', 'custom' === $selection['mode'] ? 'Kitoks variantas' : 'Apie šį modelį' );
            $line->add_meta_data( '_jd_preferences', $selection['preferences'] );
            foreach ( jd_preference_fields( $product ) as $field => $definition ) {
                if ( isset( $selection['preferences'][ $field ] ) ) {
                    $value = $selection['preferences'][ $field ];
                    $line->add_meta_data( $definition['label'], $definition['options'][ $value ] ?? $value );
                }
            }
            if ( '' !== $product->get_price() ) {
                $line->add_meta_data( 'Rodoma kaina (EUR)', $product->get_price() );
            }
            $order->add_item( $line );
        }
        $order->save();
        $order->add_order_note( 'Užklausa, ne pirkimas. Suma nėra kainos pasiūlymas. Likučiai nerezervuojami ir nenurašomi.' );
        return $order;
    } catch ( Exception $error ) {
        if ( $order && $order->get_id() ) {
            $order->delete( true );
        }
        return new WP_Error( 'save', 'Nepavyko išsaugoti užklausos. Pabandykite dar kartą.' );
    } finally {
        delete_option( $lock );
    }
}

function jd_preference_labels() {
    return array( 'size' => 'Žiedo skersmuo (mm)', 'size_2' => 'Antro žiedo skersmuo (mm)', 'material' => 'Pageidaujama medžiaga',
        'gold_color' => 'Pageidaujama aukso spalva', 'stone' => 'Pageidaujamas akmuo', 'carat' => 'Pageidaujamas svoris (ct)', 'wishes' => 'Pageidavimai' );
}
function jd_preference_value( $value ) {
    $labels = array( 'gold' => 'Auksas', 'silver' => 'Sidabras', 'yellow' => 'Geltonas', 'white' => 'Baltas', 'red' => 'Raudonas' );
    return $labels[ $value ] ?? $value;
}

add_action( 'wpcf7_before_send_mail', function ( $form, &$abort, $submission ) {
    if ( (int) $form->id() !== jd_inquiry_form_id() ) {
        return;
    }
    $payload = (string) $submission->get_posted_data( 'jd-request' );
    $data = strlen( $payload ) <= 30000 ? json_decode( $payload, true ) : null;
    if ( ! is_array( $data ) || ! wp_verify_nonce( (string) $submission->get_posted_data( 'jd-nonce' ), 'jd_catalog' ) ) {
        $abort = true;
        $submission->set_response( 'Atnaujinkite puslapį ir patikrinkite užklausos duomenis.' );
        return;
    }
    $contact = array( 'name' => (string) $submission->get_posted_data( 'jd-name' ), 'email' => (string) $submission->get_posted_data( 'jd-email' ),
        'phone' => (string) $submission->get_posted_data( 'jd-phone' ), 'comment' => (string) $submission->get_posted_data( 'jd-comment' ) );
    $order = jd_create_inquiry_order( $data['items'] ?? null, $contact, (string) ( $data['token'] ?? '' ) );
    if ( is_wp_error( $order ) ) {
        $abort = true;
        $submission->set_response( $order->get_error_message() );
        return;
    }
    $GLOBALS['jd_current_inquiry_order'] = $order;
    $summary = "\nUžklausa Nr. " . $order->get_order_number() . "\n";
    foreach ( $order->get_items() as $line ) {
        $summary .= $line->get_name() . ' · ' . $line->get_meta( 'Modelio kodas' ) . "\n";
        foreach ( $line->get_formatted_meta_data( '_', true ) as $meta ) {
            $summary .= wp_strip_all_tags( $meta->display_key . ': ' . $meta->display_value ) . "\n";
        }
    }
    $mail = $form->prop( 'mail' );
    $mail['body'] .= $summary . "\n" . $order->get_edit_order_url();
    $form->set_properties( array( 'mail' => $mail ) );
}, 10, 3 );
add_filter( 'wpcf7_skip_mail', function ( $skip, $form ) {
    $order = $GLOBALS['jd_current_inquiry_order'] ?? null;
    return (int) $form->id() === jd_inquiry_form_id() && $order && 'sent' === $order->get_meta( '_jd_mail_state' ) ? true : $skip;
}, 10, 2 );
foreach ( array( 'wpcf7_mail_sent' => 'sent', 'wpcf7_mail_failed' => 'failed' ) as $hook => $state ) {
    add_action( $hook, function ( $form ) use ( $state ) {
        $order = $GLOBALS['jd_current_inquiry_order'] ?? null;
        if ( (int) $form->id() === jd_inquiry_form_id() && $order ) {
            $order->update_meta_data( '_jd_mail_state', $state );
            $order->save();
        }
    } );
}

function jd_render_inquiry_panel() {
    if ( ! empty( $GLOBALS['jd_inquiry_panel_rendered'] ) ) {
        return;
    }
    $GLOBALS['jd_inquiry_panel_rendered'] = true;
    ?>
    <div class="nm-cart-panel-holder jd-inquiry-holder" inert>
        <div id="nm-cart-panel" role="dialog" aria-modal="true" aria-labelledby="jd-inquiry-title" aria-hidden="true">
            <div class="nm-cart-panel-header"><div class="nm-cart-panel-header-inner"><button type="button" class="jd-inquiry-close"><span id="jd-inquiry-title">Jūsų užklausa</span><span aria-hidden="true">×</span><span class="screen-reader-text">Uždaryti</span></button></div></div>
            <div class="jd-inquiry-body">
                <div class="jd-inquiry-items" aria-live="polite"></div>
                <p class="jd-inquiry-message" role="status"></p>
                <section class="jd-inquiry-success" hidden aria-labelledby="jd-inquiry-success-title">
                    <span class="jd-inquiry-success-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" focusable="false"><path d="m6 12 4 4 8-8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                    <p class="jd-inquiry-success-eyebrow">Juvelyrinis darbas</p>
                    <h2 id="jd-inquiry-success-title" tabindex="-1">Ačiū, jūsų užklausa gauta!</h2>
                    <p>Peržiūrėsime jūsų pageidavimus ir susisieksime su jumis nurodytais kontaktais.</p>
                    <button type="button" class="button jd-inquiry-success-close">Grįžti į katalogą</button>
                </section>
            </div>
            <div class="jd-inquiry-contact"><h3>Kontaktiniai duomenys</h3><p>Užklausa nėra pirkimas ar rezervacija. Kainą ir galimybes patikslinsime susisiekę.</p>
                <?php if ( jd_inquiry_form_id() ) { echo do_shortcode( '[contact-form-7 id="' . jd_inquiry_form_id() . '"]' ); } else { echo '<p>Užklausos forma ruošiama.</p>'; } ?>
            </div>
        </div><div class="nm-page-overlay nm-cart-panel-overlay jd-inquiry-overlay"></div>
    </div>
    <?php
}
add_action( 'wp_footer', function () {
    if ( function_exists( 'is_product' ) && is_product() && jd_is_catalog_product() ) {
        jd_render_inquiry_panel();
    }
}, 20 );
add_action( 'wp_enqueue_scripts', function () {
    if ( ! function_exists( 'is_product' ) || ! is_product() || ! jd_is_catalog_product() ) {
        return;
    }
    $path = get_stylesheet_directory() . '/assets/js/inquiry.js';
    if ( is_readable( $path ) ) {
        wp_enqueue_script( 'jd-inquiry', get_stylesheet_directory_uri() . '/assets/js/inquiry.js', array(), (string) filemtime( $path ), true );
        wp_localize_script( 'jd-inquiry', 'JD_INQUIRY', array( 'ajaxUrl' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'jd_catalog' ),
            'restNonce' => wp_create_nonce( 'wp_rest' ), 'formId' => jd_inquiry_form_id(), 'labels' => jd_preference_labels() ) );
    }
}, 1100 );
