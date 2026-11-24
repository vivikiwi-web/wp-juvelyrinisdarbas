<?php

/** Load child styles after Savoy, followed by the compiled components. */
function nm_child_theme_styles() {
    $theme_dir = get_stylesheet_directory();
    $theme_uri = get_stylesheet_directory_uri();

    wp_enqueue_style(
        'nm-child-theme',
        $theme_uri . '/style.css',
        array(),
        (string) filemtime( $theme_dir . '/style.css' )
    );

    $compiled_css = $theme_dir . '/assets/css/main.min.css';

    if ( is_readable( $compiled_css ) ) {
        wp_enqueue_style(
            'jd-components',
            $theme_uri . '/assets/css/main.min.css',
            array( 'nm-child-theme' ),
            (string) filemtime( $compiled_css )
        );
    }
}
add_action( 'wp_enqueue_scripts', 'nm_child_theme_styles', 1000 );

require_once get_stylesheet_directory() . '/inc/translations.php';
require_once get_stylesheet_directory() . '/inc/admin.php';
require_once get_stylesheet_directory() . '/inc/preference-fields.php';
require_once get_stylesheet_directory() . '/inc/catalog.php';
require_once get_stylesheet_directory() . '/inc/catalog-order.php';
require_once get_stylesheet_directory() . '/inc/inquiry.php';
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    require_once get_stylesheet_directory() . '/inc/catalog-cli.php';
}
