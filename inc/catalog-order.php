<?php
defined( 'ABSPATH' ) || exit;

/** Shared category priority for Savoy navigation and the default product grid. */
function jd_catalog_category_priority() {
    return array( 'vestuviniai-ziedai', 'suzadetuviu-ziedai', 'ziedai', 'juvelyriniai-dirbiniai' );
}

function jd_category_priority_sql( $column ) {
    global $wpdb;
    $sql = 'CASE ' . $column;
    foreach ( jd_catalog_category_priority() as $rank => $slug ) {
        $sql .= $wpdb->prepare( ' WHEN %s THEN %d', $slug, $rank );
    }
    return $sql . ' ELSE ' . count( jd_catalog_category_priority() ) . ' END';
}

function jd_catalog_category_menu_args( $args ) {
    $args['jd_catalog_navigation'] = true;
    $args['force_menu_order_sort'] = false;
    return $args;
}
add_filter( 'nm_shop_category_menu_args', 'jd_catalog_category_menu_args' );
add_filter( 'nm_shop_sub_category_menu_args', 'jd_catalog_category_menu_args' );

add_filter( 'terms_clauses', function ( $clauses, $taxonomies, $args ) {
    if ( ! empty( $args['jd_catalog_navigation'] ) && in_array( 'product_cat', $taxonomies, true ) ) {
        $clauses['orderby'] = 'ORDER BY ' . jd_category_priority_sql( 't.slug' ) . ' ASC, t.name ASC, t.term_id ASC';
        $clauses['order'] = '';
    }
    return $clauses;
}, 100, 3 );

// Keep the standard sorting control; category priority is part of its default option.
add_filter( 'woocommerce_default_catalog_orderby', function () { return 'menu_order'; } );
add_filter( 'posts_clauses', function ( $clauses, $query ) {
    if ( ( is_admin() && ! wp_doing_ajax() ) || $query->is_search() ||
        'product_query' !== $query->get( 'wc_query' ) || 'menu_order title' !== $query->get( 'orderby' ) ) {
        return $clauses;
    }
    global $wpdb;
    // A correlated aggregate avoids multiplying products assigned to several categories.
    $rank = 'COALESCE((SELECT MIN(' . jd_category_priority_sql( 'jd_cat.slug' ) . ') FROM ' . $wpdb->term_relationships . ' jd_rel' .
        ' INNER JOIN ' . $wpdb->term_taxonomy . ' jd_tax ON jd_tax.term_taxonomy_id = jd_rel.term_taxonomy_id' .
        ' INNER JOIN ' . $wpdb->terms . ' jd_cat ON jd_cat.term_id = jd_tax.term_id' .
        " WHERE jd_rel.object_id = {$wpdb->posts}.ID AND jd_tax.taxonomy = 'product_cat'), " . count( jd_catalog_category_priority() ) . ') ASC';
    $clauses['orderby'] = $rank . ( $clauses['orderby'] ? ', ' . $clauses['orderby'] : '' ) . ", {$wpdb->posts}.ID ASC";
    return $clauses;
}, 30, 2 );
