<?php

/** Remove only the unused Team Members plugin from Savoy's recommendations. */
function jd_remove_team_members_recommendation() {
    if ( ! class_exists( 'TGM_Plugin_Activation', false ) ) {
        return;
    }

    $tgmpa = TGM_Plugin_Activation::get_instance();
    unset( $tgmpa->plugins['nm-post-types'] );
}

// Savoy registers its plugin list at priority 10; adjust it before TGMPA checks it.
add_action( 'tgmpa_register', 'jd_remove_team_members_recommendation', 20 );
