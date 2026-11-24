<?php

/**
 * Supplement plugin catalogs without replacing their installed language packs.
 * Savoy's own nm-framework catalog is loaded by the parent theme separately.
 */
function jd_load_plugin_translation_supplements() {
    $locale = determine_locale();

    if ( 'lt_LT' !== $locale ) {
        return;
    }

    foreach ( array( 'woocommerce', 'nm-wishlist' ) as $domain ) {
        $catalog = get_stylesheet_directory() . '/languages/' . $domain . '-' . $locale . '.mo';

        if ( ! is_readable( $catalog ) ) {
            continue;
        }

        // Resolve the official just-in-time catalog first. The local catalog
        // then supplies missing keys without suppressing existing translations.
        get_translations_for_domain( $domain );
        load_textdomain( $domain, $catalog, $locale );
    }
}
add_action( 'init', 'jd_load_plugin_translation_supplements', 20 );
