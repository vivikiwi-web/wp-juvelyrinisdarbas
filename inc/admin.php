<?php

/**
 * Drop Savoy recommendations this catalog does not use.
 *
 * TGMPA keeps each slug in a protected sort list. PHP 8 throws
 * ValueError from array_multisort() when that list is longer than plugins.
 */
function jd_remove_unused_plugin_recommendations() {
    if ( ! class_exists( 'TGM_Plugin_Activation', false ) ) {
        return;
    }

    $tgmpa      = TGM_Plugin_Activation::get_instance();
    $sort_order = new ReflectionProperty( TGM_Plugin_Activation::class, 'sort_order' );
    $order      = $sort_order->getValue( $tgmpa );

    if ( ! is_array( $order ) ) {
        return;
    }

    $slugs = array(
        'nm-post-types',
        'instagram-widget-by-wpzoom',
        'nm-portfolio',
    );

    foreach ( $slugs as $slug ) {
        unset( $tgmpa->plugins[ $slug ], $order[ $slug ] );
    }

    $sort_order->setValue( $tgmpa, $order );
}

// Savoy registers its plugin list at priority 10; adjust it before TGMPA sorts it.
add_action( 'tgmpa_register', 'jd_remove_unused_plugin_recommendations', 20 );
