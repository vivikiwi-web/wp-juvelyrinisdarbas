<?php
/** Read-only local integration check, including drafts; no database writes or email. */
if ( 'juvelyrinisdarbas.local' !== wp_parse_url( home_url(), PHP_URL_HOST ) || ! current_user_can( 'manage_woocommerce' ) ) { WP_CLI::error( 'Local administrator required.' ); }
$assert = function ( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } };
$categories = get_terms( jd_catalog_category_menu_args( array( 'taxonomy' => 'product_cat', 'parent' => 0, 'hide_empty' => false ) ) );
$slugs = array_values( array_intersect( wp_list_pluck( $categories, 'slug' ), jd_catalog_category_priority() ) );
$assert( $slugs === jd_catalog_category_priority(), 'Savoy category navigation is not in the requested order.' );
$args = array( 'post_type' => 'product', 'post_status' => array( 'publish', 'draft' ), 'wc_query' => 'product_query', 'orderby' => 'menu_order title', 'order' => 'ASC', 'posts_per_page' => -1, 'fields' => 'ids' );
$query = new WP_Query( $args );
$assert( count( $query->posts ) === 64, 'Expected 64 catalog models.' );
$ranks = array(); $groups = array();
foreach ( jd_catalog_category_priority() as $rank => $slug ) { $term = get_term_by( 'slug', $slug, 'product_cat' ); if ( $term ) { $groups[ $term->term_id ] = $rank; } }
foreach ( $query->posts as $id ) {
    $product = wc_get_product( $id ); $rank = 4;
    foreach ( $product->get_category_ids() as $category ) { if ( isset( $groups[ $category ] ) ) { $rank = min( $rank, $groups[ $category ] ); } }
    $ranks[] = $rank;
}
$sorted = $ranks; sort( $sorted );
$assert( $ranks === $sorted, 'Products are not grouped by category priority.' );
$paginated = array();
for ( $page = 1; $page <= 6; $page++ ) { $part = new WP_Query( array_merge( $args, array( 'posts_per_page' => 12, 'paged' => $page ) ) ); $paginated = array_merge( $paginated, $part->posts ); }
$assert( $paginated === $query->posts && count( array_unique( $paginated ) ) === 64, 'Pagination changed order or duplicated products.' );
$explicit = new WP_Query( array_merge( $args, array( 'orderby' => 'title', 'order' => 'DESC' ) ) );
$assert( false === strpos( $explicit->request, 'jd_rel' ), 'Explicit sorting was overridden.' );
$plain = $args; unset( $plain['wc_query'] ); $plain = new WP_Query( $plain );
$assert( false === strpos( $plain->request, 'jd_rel' ), 'Non-catalog query was overridden.' );
$filtered = new WP_Query( array_merge( $args, array( 'tax_query' => array( array( 'taxonomy' => 'product_cat', 'field' => 'slug', 'terms' => 'suzadetuviu-ziedai' ) ) ) ) );
$assert( 9 === count( $filtered->posts ), 'Category filter changed results.' );
$previous_product = $GLOBALS['product'] ?? null;
try {
    $GLOBALS['product'] = wc_get_product( wc_get_product_id_by_sku( 'JD-0033' ) );
    ob_start(); jd_catalog_stock_badge(); $badge = ob_get_clean();
    $assert( false !== strpos( $badge, 'Užsakoma' ) && false === strpos( $badge, 'Neturime' ), 'Old stock label is still rendered.' );
} finally { $GLOBALS['product'] = $previous_product; }
WP_CLI::success( 'Category navigation, all 64 models, stable pagination, explicit sort preservation, category filtering and Užsakoma label verified. No database changes or mail.' );
