<?php
/** Savoy 3.2.4 panel shell: use the inquiry content only on our catalog models. */
defined( 'ABSPATH' ) || exit;
if ( function_exists( 'is_product' ) && is_product() && jd_is_catalog_product() ) {
    jd_render_inquiry_panel();
} else {
    require get_template_directory() . '/template-parts/woocommerce/cart-panel.php';
}
