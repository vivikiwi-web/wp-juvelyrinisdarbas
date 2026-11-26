<?php

/**
 * Drop Savoy's unused Team Members recommendation.
 *
 * TGMPA keeps the same slug in a protected sort list. PHP 8 throws
 * ValueError from array_multisort() when that list is longer than plugins.
 */
function jd_remove_team_members_recommendation() {
    if ( ! class_exists( 'TGM_Plugin_Activation', false ) ) {
        return;
    }

    $tgmpa = TGM_Plugin_Activation::get_instance();
    $slug  = 'nm-post-types';

    unset( $tgmpa->plugins[ $slug ] );

    $sort_order = new ReflectionProperty( TGM_Plugin_Activation::class, 'sort_order' );
    $order      = $sort_order->getValue( $tgmpa );

    if ( ! is_array( $order ) || ! array_key_exists( $slug, $order ) ) {
        return;
    }

    unset( $order[ $slug ] );
    $sort_order->setValue( $tgmpa, $order );
}

// Savoy registers its plugin list at priority 10; adjust it before TGMPA sorts it.
add_action( 'tgmpa_register', 'jd_remove_team_members_recommendation', 20 );
