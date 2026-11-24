<?php
defined( 'ABSPATH' ) || exit;

function jd_catalog_categories() {
    return array( 'vestuviniai-ziedai' => 'Vestuviniai žiedai', 'suzadetuviu-ziedai' => 'Sužadėtuvių žiedai',
        'ziedai' => 'Žiedai', 'juvelyriniai-dirbiniai' => 'Juvelyriniai dirbiniai', 'auskarai' => 'Auskarai', 'pakabukai' => 'Pakabukai' );
}

WP_CLI::add_command( 'jd catalog setup', function () {
    if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WPCF7_ContactForm' ) ) {
        WP_CLI::error( 'WooCommerce and Contact Form 7 must be active.' );
    }
    if ( jd_inquiry_form_id() && get_post( jd_inquiry_form_id() ) ) {
        WP_CLI::success( 'Existing inquiry form retained: ' . jd_inquiry_form_id() );
        return;
    }
    $form = WPCF7_ContactForm::get_template( array( 'title' => 'Gaminio užklausa', 'locale' => 'lt_LT' ) );
    $form->set_properties( array(
        'form' => '<label>Vardas [text* jd-name maxlength:100 autocomplete:name]</label>' . "\n" .
            '<label>Telefonas [tel* jd-phone maxlength:40 autocomplete:tel]</label>' . "\n" .
            '<label>El. paštas [email* jd-email maxlength:200 autocomplete:email]</label>' . "\n" .
            '<label>Komentaras [textarea jd-comment maxlength:2000]</label>' . "\n" .
            '[hidden jd-request][hidden jd-nonce]' . "\n" . '[submit "Siųsti užklausą"]',
        'mail' => array( 'active' => true, 'subject' => 'Nauja gaminio užklausa – Juvelyrinis darbas',
            'sender' => 'Juvelyrinis darbas <wordpress@juvelyrinisdarbas.lt>', 'recipient' => get_option( 'admin_email' ),
            'body' => "Vardas: [jd-name]\nTelefonas: [jd-phone]\nEl. paštas: [jd-email]\nKomentaras: [jd-comment]\n",
            'additional_headers' => 'Reply-To: [jd-email]', 'attachments' => '', 'use_html' => false, 'exclude_blank' => false ),
        'mail_2' => array( 'active' => false ),
        'messages' => array_merge( $form->prop( 'messages' ), array(
            'mail_sent_ok' => 'Ačiū! Jūsų užklausa gauta. Susisieksime su jumis.',
            'mail_sent_ng' => 'Užklausa išsaugota, tačiau pranešimo nepavyko išsiųsti. Bandykite dar kartą – užklausa nebus dubliuojama.',
            'validation_error' => 'Patikrinkite pažymėtus laukus.', 'invalid_required' => 'Užpildykite šį lauką.',
            'invalid_email' => 'Įveskite tinkamą el. pašto adresą.', 'invalid_tel' => 'Patikrinkite telefono numerį.' ) ),
    ) );
    $id = $form->save();
    if ( ! $id ) {
        WP_CLI::error( 'Could not save inquiry form.' );
    }
    update_option( 'jd_inquiry_form_id', $id, false );
    WP_CLI::success( 'Created dedicated inquiry form: ' . $id );
} );

/**
 * Import simple catalog drafts. Default is read-only dry-run.
 *
 * ## OPTIONS
 * [--only=<skus>]
 * : Comma-separated SKUs; required for a test apply.
 * [--apply]
 * : Write the selected draft products and media.
 * [--allow-full]
 * : Permit more than five drafts after owner review.
 * [--report=<path>]
 * : Write a JSON report outside the public catalog.
 */
WP_CLI::add_command( 'jd catalog import', function ( $args, $assoc ) {
    $path = get_stylesheet_directory() . '/project-files/catalog-import/products.json';
    $manifest = json_decode( file_get_contents( $path ), true );
    if ( ! is_array( $manifest ) || empty( $manifest['products'] ) ) {
        WP_CLI::error( 'Invalid catalog manifest.' );
    }
    $selected = ! empty( $assoc['only'] ) ? array_map( 'trim', explode( ',', $assoc['only'] ) ) : array();
    $products = array_values( array_filter( $manifest['products'], function ( $item ) use ( $selected ) { return ! $selected || in_array( $item['sku'], $selected, true ); } ) );
    if ( $selected && count( $selected ) !== count( $products ) ) {
        WP_CLI::error( 'Unknown or duplicate SKU in --only.' );
    }
    $apply = isset( $assoc['apply'] );
    if ( $apply && count( $products ) > 5 && ! isset( $assoc['allow-full'] ) ) {
        WP_CLI::error( 'Test batch is limited to five drafts. Full import requires owner review and --allow-full.' );
    }
    $report = array( 'mode' => $apply ? 'apply' : 'dry-run', 'created_products' => array(), 'created_attachments' => array(), 'created_terms' => array(), 'items' => array(), 'errors' => array() );
    foreach ( $products as $record ) {
        try {
            if ( 'simple' !== $record['type'] || 'draft' !== $record['status'] ) {
                throw new Exception( 'Only simple drafts are permitted by this importer.' );
            }
            $fingerprint = hash( 'sha256', wp_json_encode( $record ) );
            $ids = get_posts( array( 'post_type' => 'product', 'post_status' => 'any', 'numberposts' => 2, 'fields' => 'ids',
                'meta_key' => '_jd_catalog_id', 'meta_value' => $record['id'] ) );
            if ( count( $ids ) > 1 ) {
                throw new Exception( 'Duplicate stable catalog IDs in WordPress.' );
            }
            if ( $ids ) {
                $product = wc_get_product( $ids[0] );
                if ( $product->get_meta( '_jd_import_fingerprint' ) !== $fingerprint ) {
                    throw new Exception( 'Existing source differs. Review an explicit update; manual edits are not overwritten.' );
                }
                $report['items'][] = array( 'sku' => $record['sku'], 'action' => 'skip_existing', 'product_id' => $product->get_id(), 'preview_url' => get_preview_post_link( $product->get_id() ) );
                continue;
            }
            if ( wc_get_product_id_by_sku( $record['sku'] ) ) {
                throw new Exception( 'SKU already belongs to another product.' );
            }
            foreach ( $record['images'] as $asset ) {
                $asset_path = realpath( get_stylesheet_directory() . '/' . $asset['webp'] );
                $root = realpath( get_stylesheet_directory() . '/project-files/catalog-import/webp' );
                if ( ! $asset_path || ! $root || 0 !== strpos( $asset_path, $root . DIRECTORY_SEPARATOR ) ||
                    hash_file( 'sha256', $asset_path ) !== ( $asset['webp_sha256'] ?? '' ) ) {
                    throw new Exception( 'Missing or changed WebP asset.' );
                }
            }
            if ( ! isset( jd_catalog_categories()[ $record['category'] ] ) ) {
                throw new Exception( 'Unapproved category.' );
            }
            if ( ! $apply ) {
                $report['items'][] = array( 'sku' => $record['sku'], 'action' => 'would_create_draft', 'title' => $record['title_lt'], 'category' => $record['category'] );
                continue;
            }
            $categories = array();
            $slugs = in_array( $record['category'], array( 'auskarai', 'pakabukai' ), true ) ? array( 'juvelyriniai-dirbiniai', $record['category'] ) : array( $record['category'] );
            foreach ( $slugs as $slug ) {
                $term = get_term_by( 'slug', $slug, 'product_cat' );
                if ( ! $term ) {
                    $insert = wp_insert_term( jd_catalog_categories()[ $slug ], 'product_cat', array( 'slug' => $slug, 'parent' => $categories ? $categories[0] : 0 ) );
                    if ( is_wp_error( $insert ) ) { throw new Exception( $insert->get_error_message() ); }
                    $term = get_term( $insert['term_id'], 'product_cat' );
                    $report['created_terms'][] = $term->term_id;
                }
                $categories[] = $term->term_id;
            }
            $image_ids = array();
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
            foreach ( $record['images'] as $asset ) {
                $found = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'numberposts' => 2, 'fields' => 'ids', 'meta_key' => '_jd_asset_id', 'meta_value' => $asset['id'] ) );
                if ( count( $found ) > 1 ) { throw new Exception( 'Duplicate asset IDs.' ); }
                if ( $found ) {
                    if ( get_post_meta( $found[0], '_jd_asset_sha256', true ) !== $asset['webp_sha256'] ) { throw new Exception( 'Existing media differs; explicit review required.' ); }
                    $image_ids[] = $found[0];
                    continue;
                }
                $source = get_stylesheet_directory() . '/' . $asset['webp'];
                $temporary = wp_tempnam( basename( $source ) );
                if ( ! copy( $source, $temporary ) ) { throw new Exception( 'Could not stage image.' ); }
                $attachment_id = media_handle_sideload( array( 'name' => basename( $source ), 'tmp_name' => $temporary ), 0, $record['title_lt'] );
                if ( is_wp_error( $attachment_id ) ) { @unlink( $temporary ); throw new Exception( $attachment_id->get_error_message() ); }
                update_post_meta( $attachment_id, '_jd_asset_id', $asset['id'] );
                update_post_meta( $attachment_id, '_jd_asset_sha256', $asset['webp_sha256'] );
                update_post_meta( $attachment_id, '_wp_attachment_image_alt', $asset['alt_lt'] );
                $image_ids[] = $attachment_id;
                $report['created_attachments'][] = $attachment_id;
            }
            $product = new WC_Product_Simple();
            $product->set_name( $record['title_lt'] );
            $product->set_slug( $record['slug'] );
            $product->set_sku( $record['sku'] );
            $product->set_status( 'draft' );
            $product->set_description( wp_kses_post( $record['description_lt'] ) );
            $product->set_short_description( wp_kses_post( $record['short_description_lt'] ) );
            $product->set_regular_price( null === $record['regular_price'] ? '' : $record['regular_price'] );
            $product->set_manage_stock( false );
            $product->set_stock_status( 'outofstock' );
            $product->set_category_ids( $categories );
            $product->set_image_id( $image_ids[0] );
            $product->set_gallery_image_ids( array_slice( $image_ids, 1 ) );
            $product->set_reviews_allowed( false );
            $product->update_meta_data( '_jd_catalog_id', $record['id'] );
            $product->update_meta_data( '_jd_import_fingerprint', $fingerprint );
            $product->update_meta_data( '_jd_custom_request', $record['custom_request_enabled'] ? 'yes' : 'no' );
            $product->update_meta_data( '_jd_is_ring', $record['is_ring'] ? 'yes' : 'no' );
            $product->update_meta_data( '_jd_is_pair', $record['is_pair'] ? 'yes' : 'no' );
            $product->set_attributes( jd_catalog_attributes( $record['confirmed_specs'] ) );
            $id = $product->save();
            $report['created_products'][] = $id;
            $report['items'][] = array( 'sku' => $record['sku'], 'action' => 'created_draft', 'product_id' => $id, 'preview_url' => get_preview_post_link( $id ) );
        } catch ( Exception $error ) {
            $report['errors'][] = array( 'sku' => $record['sku'] ?? '', 'message' => $error->getMessage() );
        }
    }
    if ( ! empty( $assoc['report'] ) ) {
        if ( false === file_put_contents( $assoc['report'], wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) . "\n" ) ) {
            WP_CLI::error( 'Could not write report.' );
        }
    }
    WP_CLI::log( wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
    if ( $report['errors'] ) { WP_CLI::error( 'Import has conflicts/errors; inspect the report.' ); }
    WP_CLI::success( ( $apply ? 'Applied' : 'Dry-run' ) . ': ' . count( $products ) . ' products; all are simple drafts.' );
} );

/** Move legacy facts to standard WC attributes; never overwrite existing attributes or stock. */
WP_CLI::add_command( 'jd catalog migrate-attributes', function ( $args, $assoc ) {
    $ids = get_posts( array( 'post_type' => 'product', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_jd_catalog_id' ) );
    $plans = array();
    foreach ( $ids as $id ) {
        $product = wc_get_product( $id );
        $specs = array();
        foreach ( jd_catalog_spec_fields() as $key => $label ) {
            $specs[ $key ] = $product->get_meta( '_jd_spec_' . $key );
        }
        $attributes = $product->get_attributes();
        foreach ( jd_catalog_attributes( $specs ) as $key => $attribute ) {
            // A standard attribute (including a global taxonomy) is authoritative.
            $exists = false;
            foreach ( $attributes as $current ) {
                if ( sanitize_title( wc_attribute_label( $current->get_name() ) ) === $key ) {
                    $exists = true;
                    break;
                }
            }
            if ( ! $exists ) {
                $attribute->set_position( count( $attributes ) );
                $attributes[ $key ] = $attribute;
            }
        }
        $plans[] = array( 'id' => $id, 'sku' => $product->get_sku(), 'attributes' => array_keys( $attributes ), 'stock' => $product->get_stock_status(), 'status' => $product->get_status() );
        if ( isset( $assoc['apply'] ) ) {
            $product->set_attributes( $attributes );
            foreach ( jd_catalog_spec_fields() as $key => $label ) {
                $product->delete_meta_data( '_jd_spec_' . $key );
            }
            foreach ( array( '_jd_in_salon', '_jd_availability', '_jd_example_price' ) as $key ) {
                $product->delete_meta_data( $key );
            }
            $product->save();
        }
    }
    WP_CLI::log( wp_json_encode( $plans, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
    WP_CLI::success( isset( $assoc['apply'] ) ? 'Migrated; existing attributes, prices, stock and publication statuses preserved.' : 'Dry-run only; no writes.' );
} );
